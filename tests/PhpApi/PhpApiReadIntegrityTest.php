<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Splicewire\Beam\Docs\PhpApi\ArtifactWriter;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * php-api-reference slice 2 amend (review REVISE 47b37664):
 *
 * 1. A malformed manifest, index or selected symbol fails CLOSED at its boundary (unavailable / 404), before any key is
 *    read unchecked or any Data is hydrated: no 500, no stack, no artifact path in the response.
 * 2. The typed wire carries the generated document WHOLE: every published symbol's HTTP payload equals its artifact file
 *    (less the file's own `schema` stamp) in both audiences, so 01b's inherited `abstract` and a public original's
 *    `original` survive, and a private original stays absent.
 */
class PhpApiReadIntegrityTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().'/beam-docs-php-api-integrity-'.bin2hex(random_bytes(6));
        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        $site = BeamUxEntry::rootFor();
        $docs = BeamUxEntry::create(['slug' => 'docs', 'title' => 'Docs', 'type' => 'page', 'segment' => '/docs', 'parent_id' => $site->getKey(), 'layout' => 'DocsLayout', 'requirements' => ['beam-docs']]);
        BeamUxEntry::create(['slug' => 'docs-php-api', 'title' => 'PHP API Reference', 'type' => 'page', 'segment' => 'php', 'parent_id' => $docs->getKey(), 'template' => 'SpreadTemplate', 'requirements' => ['beam-docs-php-api']]);

        $base = dirname(__DIR__).'/Fixtures/PhpApi';
        $packages = [];
        foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $p) {
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

    private function generate(string $audience = 'public'): void
    {
        config(['beam.docs.php_api.audience' => $audience]);
        Artisan::call('splicewire:beam:docs:php-api:generate');
    }

    private function payload(TestResponse $response): array
    {
        $json = $response->assertOk()->json();

        return is_array($json['data'] ?? null) ? $json['data'] : $json;
    }

    private function symbol(string $fqn): TestResponse
    {
        return $this->getJson('/beam/docs/php-api-symbol?'.http_build_query(['fqn' => $fqn]));
    }

    private function artifactFile(string $relative): array
    {
        return json_decode((string) file_get_contents($this->out.'/'.$relative), true);
    }

    private function rewrite(string $relative, array $value): void
    {
        file_put_contents($this->out.'/'.$relative, json_encode($value));
    }

    private function inherited(array $symbol): array
    {
        return collect($symbol['inherited'])->keyBy(fn (array $m) => "{$m['kind']} {$m['name']}")->all();
    }

    /** A bad read is the uniform 404: no server error, no exception text, no artifact path. */
    private function assertClosed(TestResponse $response, string $why): void
    {
        $this->assertSame(404, $response->getStatusCode(), $why);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString($this->out, $body, $why);
        $this->assertStringNotContainsString('Exception', $body, $why);
        $this->assertStringNotContainsString('.php', $body, $why);
    }

    // ---- 2. the whole generated document reaches the wire ------------------------------------------------------------

    #[DataProvider('audiences')]
    public function test_every_published_symbol_reaches_the_wire_exactly_as_generated(string $audience): void
    {
        $this->generate($audience);
        $count = 0;

        foreach ($this->artifactFile('manifest.json')['packages'] as $package) {
            if ($package['index'] === null) {
                continue;
            }
            foreach ($this->artifactFile($package['index'])['symbols'] as $row) {
                $file = $this->artifactFile($row['path']);
                unset($file['schema']);
                $this->assertEquals($file, $this->payload($this->symbol($row['fqn'])), "{$audience} {$row['fqn']}");
                $count++;
            }
        }

        $this->assertGreaterThan(20, $count, 'the comparison ran over the whole fixture set');
    }

    #[DataProvider('audiences')]
    public function test_inherited_abstract_state_and_a_public_original_survive_while_a_private_original_stays_absent(string $audience): void
    {
        $this->generate($audience);

        $kit = $this->inherited($this->payload($this->symbol('Fixture\ApiE\Kit')));
        $this->assertSame('shout', $kit['method loud']['original'], 'a public original keeps its name on the wire');
        $this->assertArrayNotHasKey('original', $kit['method revealed'], 'a private original\'s name is never on the wire');
        $this->assertSame('public function revealed(int $times = 2, ?string $label = null): void', $kit['method revealed']['declaration']['signature']);
        $this->assertStringNotContainsString('secretly', (string) $this->symbol('Fixture\ApiE\Kit')->getContent());

        $this->assertTrue($this->inherited($this->payload($this->symbol('Fixture\ApiE\Needs')))['method foo']['abstract'], 'an unmet requirement stays abstract');
        $child = $this->inherited($this->payload($this->symbol('Fixture\ApiE\ChildOfPublished')));
        $this->assertFalse($child['method foo']['abstract'], 'false is carried, not dropped');
        $this->assertFalse($child['method bar']['abstract']);
    }

    // ---- 1. malformed structure fails closed --------------------------------------------------------------------------

    public function test_an_ok_package_row_missing_its_index_key_is_unavailable_not_a_500(): void
    {
        $this->generate();
        $manifest = $this->artifactFile('manifest.json');
        unset($manifest['packages'][0]['index']);
        $this->rewrite('manifest.json', $manifest);

        $this->assertClosed($this->getJson('/beam/docs/php-api-index'), 'index');
        $this->assertClosed($this->symbol('Fixture\ApiA\Box'), 'symbol');
    }

    public function test_malformed_manifest_rows_are_unavailable(): void
    {
        $cases = [
            'status missing' => fn (array $m) => array_replace_recursive($m, ['packages' => [0 => ['status' => null]]]),
            'name not a string' => fn (array $m) => array_replace_recursive($m, ['packages' => [0 => ['name' => 7]]]),
            'version not a string' => fn (array $m) => array_replace_recursive($m, ['packages' => [0 => ['version' => ['x']]]]),
            'ok row with null index' => fn (array $m) => array_replace_recursive($m, ['packages' => [0 => ['index' => null]]]),
            'row not an object' => function (array $m) {
                $m['packages'][0] = 'fixture/php-api-a';

                return $m;
            },
        ];

        foreach ($cases as $why => $break) {
            $this->generate();
            $this->rewrite('manifest.json', $break($this->artifactFile('manifest.json')));
            $this->assertClosed($this->getJson('/beam/docs/php-api-index'), $why);
            $this->assertClosed($this->symbol('Fixture\ApiA\Box'), $why);
        }
    }

    public function test_malformed_index_rows_close_the_index(): void
    {
        $index = ArtifactWriter::indexPath('fixture/php-api-a');
        $cases = [
            'kind missing' => function (array $i) {
                unset($i['symbols'][0]['kind']);

                return $i;
            },
            'summary an array' => fn (array $i) => array_replace_recursive($i, ['symbols' => [0 => ['summary' => ['x']]]]),
            'symbols not a list' => fn (array $i) => array_replace($i, ['symbols' => ['a' => 'b']]),
            'row not an object' => fn (array $i) => array_replace_recursive($i, ['symbols' => [0 => 'Fixture\ApiA\Box']]),
        ];

        foreach ($cases as $why => $break) {
            $this->generate();
            $this->rewrite($index, $break($this->artifactFile($index)));
            $this->assertClosed($this->getJson('/beam/docs/php-api-index'), $why);
        }
    }

    public function test_an_identity_only_or_structurally_incomplete_symbol_is_404(): void
    {
        $path = ArtifactWriter::symbolPath('Fixture\ApiA\Box');
        $cases = [
            'identity only' => fn (array $s) => ['schema' => $s['schema'], 'fqn' => $s['fqn'], 'package' => $s['package']],
            'kind missing' => fn (array $s) => array_diff_key($s, ['kind' => true]),
            'modifiers a string' => fn (array $s) => array_replace($s, ['modifiers' => 'final']),
            'line not an int' => fn (array $s) => array_replace($s, ['line' => 'twelve']),
            'doc a string' => fn (array $s) => array_replace($s, ['doc' => 'A box.']),
            'member missing its name' => function (array $s) {
                unset($s['methods'][0]['name']);

                return $s;
            },
            'member params a string' => function (array $s) {
                $s['methods'][0]['params'] = 'int $x';

                return $s;
            },
            'member not an object' => function (array $s) {
                $s['methods'][0] = 'value()';

                return $s;
            },
            'tag not an object' => function (array $s) {
                $s['tags'] = ['@api'];

                return $s;
            },
            'link missing published' => function (array $s) {
                $s['implements'] = [['fqn' => 'Fixture\ApiA\Contracts\Shape']];

                return $s;
            },
            'inherited declaration empty' => function (array $s) {
                $s['inherited'] = [['kind' => 'method', 'name' => 'x', 'from' => 'Y', 'visibility' => 'public', 'declaration' => []]];

                return $s;
            },
        ];

        foreach ($cases as $why => $break) {
            $this->generate();
            $this->rewrite($path, $break($this->artifactFile($path)));
            $this->assertClosed($this->symbol('Fixture\ApiA\Box'), $why);
            // Only the SELECTED symbol is judged: the index and every other symbol still read.
            $this->payload($this->getJson('/beam/docs/php-api-index'));
            $this->payload($this->symbol('Fixture\ApiA\Kind'));
        }
    }
}
