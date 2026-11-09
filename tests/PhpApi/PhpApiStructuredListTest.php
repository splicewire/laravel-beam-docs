<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Splicewire\Beam\Docs\PhpApi\ArtifactWriter;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * php-api-reference 02b (owner commission 04:25Z; slice 2 final review REVISE 8b7f533b): a declared `list<…>` is a
 * LIST on the wire, or the read is the uniform 404. A collection of otherwise VALID items under string keys passes
 * Laravel's `array` rule and keeps its keys through laravel-data hydration and transformation, so it would be served as
 * a JSON object with HTTP 200. It is rejected, never normalized.
 *
 * Data-driven over the real generated artifact: for every published symbol of fixtures pkg-a…g (pkg-g: 02b, attribute and parameter-attribute lists) in both audiences, every
 * declared collection that holds items (top level, inside each member, each parameter and each inherited effective
 * declaration) has its OWN valid items re-keyed under string keys, one collection at a time, and the symbol must then
 * be 404; the file is restored after each probe. Coverage asserts every declared collection pattern was probed.
 */
class PhpApiStructuredListTest extends TestCase
{
    /** Every declared list on the symbol wire, as a pattern (`member` = a constant, case, property, method or inherited declaration). */
    private const PATTERNS = [
        'templates', 'extends', 'implements', 'traits', 'attributes', 'tags', 'constants', 'cases', 'properties', 'methods',
        'see', 'inherited', 'modifiers', 'doc', 'implementers', 'attributes.*.arguments',
        'member.attributes', 'member.tags', 'member.params', 'member.templates', 'member.doc', 'member.attributes.*.arguments',
        'member.params.*.attributes', 'member.params.*.attributes.*.arguments',
    ];

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        // Hundreds of probes per test: the docs group's `throttle:60,1` would answer 429 and mask the verdict (run r00).
        // Only the throttle is lifted; the docs gate, PhpApiReadGate and the ops still run (PhpApiContractsTest asserts the
        // throttle is mounted).
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->out = sys_get_temp_dir().'/beam-docs-php-api-lists-'.bin2hex(random_bytes(6));
        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        $site = BeamUxEntry::rootFor();
        $docs = BeamUxEntry::create(['slug' => 'docs', 'title' => 'Docs', 'type' => 'page', 'segment' => '/docs', 'parent_id' => $site->getKey(), 'layout' => 'DocsLayout', 'requirements' => ['beam-docs']]);
        BeamUxEntry::create(['slug' => 'docs-php-api', 'title' => 'PHP API Reference', 'type' => 'page', 'segment' => 'php', 'parent_id' => $docs->getKey(), 'template' => 'SpreadTemplate', 'requirements' => ['beam-docs-php-api']]);

        $base = dirname(__DIR__).'/Fixtures/PhpApi';
        $packages = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f', 'g'] as $p) {
            $packages['fixture/php-api-'.$p] = $base.'/pkg-'.$p;
        }
        config(['beam.docs.visibility' => 'public', 'beam.docs.php_api.artifact' => $this->out, 'beam.docs.php_api.packages' => $packages]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        config(['beam.docs.php_api.audience' => null]);
        parent::tearDown();
    }

    public static function audiences(): array
    {
        return ['public' => ['public'], 'internal' => ['internal']];
    }

    private function generate(string $audience): void
    {
        config(['beam.docs.php_api.audience' => $audience]);
        Artisan::call('splicewire:beam:docs:php-api:generate');
    }

    private function artifactFile(string $relative): array
    {
        return json_decode((string) file_get_contents($this->out.'/'.$relative), true);
    }

    private function symbol(string $fqn): TestResponse
    {
        return $this->getJson('/beam/docs/php-api-symbol?'.http_build_query(['fqn' => $fqn]));
    }

    /** @return list<array{fqn: string, path: string}> every published symbol row */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->artifactFile('manifest.json')['packages'] as $package) {
            if ($package['index'] !== null) {
                array_push($rows, ...$this->artifactFile($package['index'])['symbols']);
            }
        }

        return $rows;
    }

    /** @return list<string> every declared collection path in one symbol document */
    private function paths(array $symbol): array
    {
        $paths = ['templates', 'extends', 'implements', 'traits', 'attributes', 'tags', 'constants', 'cases', 'properties',
            'methods', 'see', 'inherited', 'modifiers', 'doc', 'implementers'];
        foreach (array_keys($symbol['attributes']) as $i) {
            $paths[] = "attributes.{$i}.arguments";
        }
        foreach (['constants', 'cases', 'properties', 'methods'] as $kind) {
            foreach ($symbol[$kind] as $i => $member) {
                array_push($paths, ...$this->memberPaths("{$kind}.{$i}", $member));
            }
        }
        foreach ($symbol['inherited'] as $i => $row) {
            if (isset($row['declaration'])) {
                array_push($paths, ...$this->memberPaths("inherited.{$i}.declaration", $row['declaration']));
            }
        }

        return $paths;
    }

    /** @return list<string> */
    private function memberPaths(string $at, array $member): array
    {
        $paths = [];
        foreach (['attributes', 'tags', 'params', 'templates', 'doc'] as $key) {
            if (array_key_exists($key, $member)) {
                $paths[] = "{$at}.{$key}";
            }
        }
        foreach (array_keys($member['attributes']) as $i) {
            $paths[] = "{$at}.attributes.{$i}.arguments";
        }
        foreach ($member['params'] ?? [] as $j => $param) {
            $paths[] = "{$at}.params.{$j}.attributes";
            foreach (array_keys($param['attributes']) as $i) {
                $paths[] = "{$at}.params.{$j}.attributes.{$i}.arguments";
            }
        }

        return $paths;
    }

    private function pattern(string $path): string
    {
        $pattern = preg_replace('/\.\d+(?=\.|$)/', '.*', $path);

        return preg_replace('/^(?:(?:constants|cases|properties|methods)\.\*|inherited\.\*\.declaration)\./', 'member.', $pattern);
    }

    #[DataProvider('audiences')]
    public function test_a_declared_list_of_valid_items_under_string_keys_is_404_at_every_depth(string $audience): void
    {
        $this->generate($audience);
        $leaks = [];
        $probed = [];
        $probes = 0;

        foreach ($this->rows() as $row) {
            $file = $this->out.'/'.$row['path'];
            $original = (string) file_get_contents($file);
            $symbol = json_decode($original, true);

            foreach ($this->paths($symbol) as $path) {
                $list = data_get($symbol, $path);
                if (! is_array($list) || $list === [] || ! array_is_list($list)) {
                    continue;
                }
                $keyed = array_combine(array_map(fn (int $i): string => 'item'.$i, array_keys($list)), $list);
                $broken = $symbol;
                data_set($broken, $path, $keyed);
                file_put_contents($file, json_encode($broken));

                $response = $this->symbol($row['fqn']);
                if ($response->getStatusCode() !== 404) {
                    $leaks[] = "{$row['fqn']} {$path} → {$response->getStatusCode()}";
                }
                $probed[$this->pattern($path)] = true;
                $probes++;
                file_put_contents($file, $original);
            }
        }

        $this->assertSame([], $leaks, "a keyed declared list was served under {$audience}");
        $this->assertSame([], array_values(array_diff(self::PATTERNS, array_keys($probed))), 'every declared collection pattern was probed');
        $this->assertGreaterThan(100, $probes);
    }

    #[DataProvider('audiences')]
    public function test_valid_and_empty_lists_still_read_as_lists(string $audience): void
    {
        $this->generate($audience);
        $empty = 0;

        foreach ($this->rows() as $row) {
            $file = $this->artifactFile($row['path']);
            unset($file['schema']);
            $json = $this->symbol($row['fqn'])->assertOk()->json();
            $payload = is_array($json['data'] ?? null) ? $json['data'] : $json;
            $this->assertEquals($file, $payload, $row['fqn']);
            $empty += count(array_filter(['cases', 'traits', 'templates', 'see', 'implementers'], fn ($k) => $file[$k] === []));
        }

        $this->assertGreaterThan(0, $empty, 'empty declared lists were among those read');
    }

    public function test_keyed_index_rows_close_the_index_without_normalizing(): void
    {
        $this->generate('public');
        $path = ArtifactWriter::indexPath('fixture/php-api-a');
        $index = $this->artifactFile($path);
        $index['symbols'] = array_combine(array_map(fn ($r) => $r['fqn'], $index['symbols']), $index['symbols']);
        file_put_contents($this->out.'/'.$path, json_encode($index));

        $this->getJson('/beam/docs/php-api-index')->assertNotFound();
        $this->symbol('Fixture\ApiA\Box')->assertNotFound();
    }
}
