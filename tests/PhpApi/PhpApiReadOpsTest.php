<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Splicewire\Beam\Docs\Data\PhpApiIndexData;
use Splicewire\Beam\Docs\Data\PhpApiSymbolData;
use Splicewire\Beam\Docs\PhpApi\ArtifactWriter;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * php-api-reference slice 2: `beam-docs/php-api-index` and `beam-docs/php-api-symbol` read ONLY what the generated
 * artifact holds, typed, and only the paths its own manifest and indexes name. Input never forms a filesystem path: the
 * FQN is looked up in the indexes, and the path the index row holds is checked again (shape, no `..`, real path inside
 * the artifact root) before it is opened.
 */
class PhpApiReadOpsTest extends TestCase
{
    private string $out;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().'/beam-docs-php-api-ops-'.bin2hex(random_bytes(6));
        $this->outside = sys_get_temp_dir().'/beam-docs-php-api-outside-'.bin2hex(random_bytes(6));
        mkdir($this->outside);
        file_put_contents($this->outside.'/loot.json', json_encode(['schema' => 'beam-docs.php-api/v1', 'fqn' => 'LOOT', 'symbols' => [['fqn' => 'LOOT', 'kind' => 'class', 'summary' => 'LOOT', 'path' => 'symbols/x.json']]]));

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        $site = BeamUxEntry::rootFor();
        $docs = BeamUxEntry::create(['slug' => 'docs', 'title' => 'Docs', 'type' => 'page', 'segment' => '/docs', 'parent_id' => $site->getKey(), 'layout' => 'DocsLayout', 'requirements' => ['beam-docs']]);
        BeamUxEntry::create(['slug' => 'docs-php-api', 'title' => 'PHP API Reference', 'type' => 'page', 'segment' => 'php', 'parent_id' => $docs->getKey(), 'template' => 'SpreadTemplate', 'requirements' => ['beam-docs-php-api']]);

        $base = dirname(__DIR__).'/Fixtures/PhpApi';
        config([
            'beam.docs.visibility' => 'public',
            'beam.docs.php_api.artifact' => $this->out,
            'beam.docs.php_api.packages' => ['fixture/php-api-a' => $base.'/pkg-a', 'fixture/php-api-c' => $base.'/pkg-c'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        File::deleteDirectory($this->outside);
        config(['beam.docs.php_api.audience' => null]);
        parent::tearDown();
    }

    private function generate(?string $audience = null): void
    {
        config(['beam.docs.php_api.audience' => $audience]);
        Artisan::call('splicewire:beam:docs:php-api:generate');
    }

    private function payload(TestResponse $response): array
    {
        $json = $response->assertOk()->json();

        return is_array($json['data'] ?? null) ? $json['data'] : $json;
    }

    private function index(): TestResponse
    {
        return $this->getJson('/beam/docs/php-api-index');
    }

    private function symbol(mixed $fqn): TestResponse
    {
        return $this->getJson('/beam/docs/php-api-symbol?'.http_build_query(['fqn' => $fqn]));
    }

    private function manifest(): array
    {
        return json_decode((string) file_get_contents($this->out.'/manifest.json'), true);
    }

    public function test_the_public_index_lists_only_published_symbols_typed_and_without_artifact_paths(): void
    {
        $this->generate();
        $index = $this->payload($this->index());

        $this->assertSame('public', $index['audience']);
        $this->assertSame(['fixture/php-api-a', 'fixture/php-api-c'], array_column($index['packages'], 'name'));
        $a = $index['packages'][0];
        $fqns = array_column($a['symbols'], 'fqn');
        $this->assertContains('Fixture\ApiA\Box', $fqns);
        $this->assertContains('Fixture\ApiA\Kind', $fqns);
        $this->assertNotContains('Fixture\ApiA\Hidden', $fqns, 'unmarked symbols are not public');
        $this->assertNotContains('Fixture\ApiA\Secret', $fqns, '@internal is never published');
        $this->assertSame([], $index['packages'][1]['symbols'], 'a listed package with no @api symbol is listed empty');
        $this->assertSame(['fqn', 'kind', 'summary'], array_keys($a['symbols'][0]), 'rows never carry the artifact-internal path');
        $this->assertSame(['name', 'version', 'published', 'symbols'], array_keys($a));
        $this->assertSame(json_decode(json_encode(PhpApiIndexData::from($index)->toArray()), true), $index, 'the wire is the typed Data');
    }

    public function test_the_internal_index_adds_unmarked_symbols_and_still_never_internal_ones(): void
    {
        $this->generate('internal');
        $fqns = array_column($this->payload($this->index())['packages'][0]['symbols'], 'fqn');

        $this->assertSame('internal', $this->payload($this->index())['audience']);
        $this->assertContains('Fixture\ApiA\Hidden', $fqns);
        $this->assertNotContains('Fixture\ApiA\Secret', $fqns);
        $this->payload($this->symbol('Fixture\ApiA\Hidden'));
        $this->symbol('Fixture\ApiA\Secret')->assertNotFound();
    }

    public function test_a_symbol_is_the_generated_document_typed(): void
    {
        $this->generate();
        $file = json_decode((string) file_get_contents($this->out.'/'.ArtifactWriter::symbolPath('Fixture\ApiA\Box')), true);
        $box = $this->payload($this->symbol('Fixture\ApiA\Box'));

        $this->assertSame('Fixture\ApiA\Box', $box['fqn']);
        $this->assertSame('class', $box['kind']);
        $this->assertSame($file['doc'], $box['doc'], 'the public prose exactly as generated (citations stripped, withheld paragraphs absent)');
        $this->assertSame(array_column($file['methods'], 'name'), array_column($box['methods'], 'name'));
        $this->assertSame(array_column($file['methods'], 'signature'), array_column($box['methods'], 'signature'));
        $this->assertNotContains('secret', array_column($box['methods'], 'name'));
        $this->assertArrayNotHasKey('comment', $box, 'only the internal audience carries the whole comment');
        $this->assertSame($file['implements'], $box['implements']);
        $this->assertSame(json_decode(json_encode(PhpApiSymbolData::from($box)->toArray()), true), $box, 'the wire is the typed Data');
    }

    public function test_the_internal_audience_carries_the_whole_comment(): void
    {
        $this->generate('internal');
        $box = $this->payload($this->symbol('Fixture\ApiA\Box'));

        $this->assertIsString($box['comment']);
        $this->assertStringContainsString('launch ticket 23', $box['comment']);
    }

    public function test_an_unpublished_or_unknown_fqn_is_404(): void
    {
        $this->generate();

        foreach (['Fixture\ApiA\Hidden', 'Fixture\ApiA\Secret', 'Fixture\ApiA\Nope', 'fixture\apia\box'] as $fqn) {
            $this->symbol($fqn)->assertNotFound();
        }
    }

    public function test_hostile_input_never_forms_a_path(): void
    {
        $this->generate();
        $hostile = [
            '', '../manifest', '../../../../etc/passwd', 'manifest.json', 'report.json', ArtifactWriter::symbolPath('Fixture\ApiA\Box'),
            'symbols/'.hash('sha256', 'Fixture\ApiA\Box'), $this->outside.'/loot.json', "Fixture\\ApiA\0\\Box", "Fixture\0/../manifest", str_repeat('A', 5000),
            'php://filter/resource=manifest.json', 'file:///etc/passwd',
        ];

        foreach ($hostile as $fqn) {
            $response = $this->symbol($fqn);
            $this->assertSame(404, $response->getStatusCode(), json_encode($fqn));
            $this->assertStringNotContainsString('LOOT', $response->getContent());
        }

        // The `web` group's TrimStrings strips edge whitespace and a trailing NUL before the op runs (PHP trim()'s default
        // set): the result is the same published KEY, looked up in the index, never a path.
        $this->assertSame('Fixture\ApiA\Box', $this->payload($this->symbol("Fixture\\ApiA\\Box\0"))['fqn']);
        $this->assertSame('Fixture\ApiA\Box', $this->payload($this->symbol(' Fixture\ApiA\Box '))['fqn']);

        $this->getJson('/beam/docs/php-api-symbol?fqn[]=Fixture%5CApiA%5CBox')->assertNotFound();
        $this->getJson('/beam/docs/php-api-symbol')->assertNotFound();
    }

    public function test_an_index_row_pointing_outside_the_artifact_is_never_opened(): void
    {
        $this->generate();
        $indexFile = $this->out.'/'.ArtifactWriter::indexPath('fixture/php-api-a');
        $index = json_decode((string) file_get_contents($indexFile), true);
        $paths = ['../'.basename($this->outside).'/loot.json', $this->outside.'/loot.json', 'symbols/../manifest.json', 'symbols/../../loot.json', 'report.json'];

        foreach ($paths as $path) {
            foreach ($index['symbols'] as &$row) {
                if ($row['fqn'] === 'Fixture\ApiA\Box') {
                    $row['path'] = $path;
                }
            }
            unset($row);
            file_put_contents($indexFile, json_encode($index));

            $response = $this->symbol('Fixture\ApiA\Box');
            $response->assertNotFound();
            $this->assertStringNotContainsString('LOOT', $response->getContent(), $path);
        }
    }

    public function test_a_symbol_file_symlinked_out_of_the_artifact_is_refused(): void
    {
        $this->generate();
        $target = $this->out.'/'.ArtifactWriter::symbolPath('Fixture\ApiA\Box');
        unlink($target);
        symlink($this->outside.'/loot.json', $target);

        $response = $this->symbol('Fixture\ApiA\Box');
        $response->assertNotFound();
        $this->assertStringNotContainsString('LOOT', $response->getContent());
    }

    public function test_a_packages_directory_symlinked_out_of_the_artifact_is_refused(): void
    {
        $this->generate();
        File::copyDirectory($this->out.'/packages', $this->outside.'/packages');
        File::deleteDirectory($this->out.'/packages');
        symlink($this->outside.'/packages', $this->out.'/packages');

        $this->index()->assertNotFound();
        $this->symbol('Fixture\ApiA\Box')->assertNotFound();
    }

    public function test_a_manifest_naming_an_index_outside_the_artifact_is_off(): void
    {
        $this->generate();
        $manifest = $this->manifest();
        $manifest['packages'][0]['index'] = '../'.basename($this->outside).'/loot.json';
        file_put_contents($this->out.'/manifest.json', json_encode($manifest));

        $response = $this->index();
        $response->assertNotFound();
        $this->assertStringNotContainsString('LOOT', $response->getContent());
    }

    public function test_an_artifact_root_that_is_a_symlink_is_off(): void
    {
        $this->generate();
        rename($this->out, $this->outside.'/real');
        symlink($this->outside.'/real', $this->out);

        $this->index()->assertNotFound();
        $this->symbol('Fixture\ApiA\Box')->assertNotFound();
    }

    public function test_a_direct_call_is_404_before_input_validation_when_off(): void
    {
        config(['beam.docs.php_api.packages' => []]);

        $this->index()->assertNotFound();
        $this->getJson('/beam/docs/php-api-symbol?fqn[]=x')->assertNotFound();
        $this->symbol('Fixture\ApiA\Box')->assertNotFound();
    }

    public function test_the_ops_follow_the_seeded_entry_not_the_config_alone(): void
    {
        $this->generate();
        $this->payload($this->index());

        // The site unpublishes the seeded page: the data routes go with it.
        BeamUxEntry::where('slug', 'docs-php-api')->update(['access' => json_encode([])]);
        $this->index()->assertNotFound();
        $this->symbol('Fixture\ApiA\Box')->assertNotFound();

        // No seeded entry at all: nothing to read through.
        BeamUxEntry::where('slug', 'docs-php-api')->forceDelete();
        $this->index()->assertNotFound();
    }
}
