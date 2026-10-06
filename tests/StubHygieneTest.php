<?php

namespace Splicewire\Beam\Docs\Tests;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * docs-walkthrough DOC-10, DOCS-11: no author note or provenance prose reaches a reader. The package's docs stubs become
 * every host's docs landing, and the landing told readers it was "Seeded by `splicewire/laravel-beam-docs`", a
 * "`BeamUxEntry` row like any other" that "Nothing re-asserts", on a site that "documents **itself**". Provenance lives
 * in the row's columns (DM2), the doctor and the authoring ribbon, never in the body. The landing is titled from the
 * host's brand (`beam.brand.name`), not "Documentation".
 */
class StubHygieneTest extends TestCase
{
    /** The phrases DOC-10 names, plus the author-voice markers they travelled with. */
    public const META_PROSE = ['Seeded by', 'Nothing re-asserts', 'BeamUxEntry', 'row this site owns', 'This site documents itself', 'documents **itself**', 'package seeding', 'this package is removed'];

    public function test_no_docs_stub_carries_author_or_provenance_prose(): void
    {
        $found = [];
        foreach (glob(dirname(__DIR__).'/stubs/docs/*.mdx') as $stub) {
            foreach (self::META_PROSE as $phrase) {
                if (str_contains((string) file_get_contents($stub), $phrase)) {
                    $found[] = basename($stub).': '.$phrase;
                }
            }
        }

        $this->assertSame([], $found);
    }

    /** review-r1: a brand with MDX- or YAML-significant characters titles the landing intact and breaks neither parse. */
    public function test_a_brand_with_mdx_and_yaml_characters_is_escaped_for_each_context(): void
    {
        $bodies = $this->seedWithBrand('Acme {Cloud} <Beta>: #1 "pro"');

        $root = BeamUxEntry::where('slug', 'docs')->sole();
        $this->assertSame('Acme {Cloud} <Beta>: #1 "pro" documentation', $root->title);
        $written = json_encode($bodies, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('# Acme &#123;Cloud&#125; &lt;Beta&gt;: #1', $written);
        $this->assertStringNotContainsString('# Acme {Cloud}', $written);
    }

    public function test_the_seeded_landing_is_titled_from_the_brand(): void
    {
        $bodies = $this->seedWithBrand('Acme Cloud');

        $root = BeamUxEntry::where('slug', 'docs')->sole();
        $this->assertSame('Acme Cloud documentation', $root->title);
        $written = json_encode($bodies, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('# Acme Cloud documentation', $written);
        $this->assertStringNotContainsString('{{ brand', $written);
    }

    /**
     * Seed the docs root under `$brand` and return the bodies written to the store. The body store and compiler are faked
     * as DocsSeedTest fakes them: these tests read the seeded ROW and the written source, not compiled bytes.
     *
     * @return list<mixed>
     */
    private function seedWithBrand(string $brand): array
    {
        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        config(['beam.brand.name' => $brand, 'beam.ux.compile.disk' => 'docs-artifacts']);
        Storage::fake('docs-artifacts');
        $bodies = [];
        $driver = Mockery::mock(StorageDriver::class);
        $driver->shouldReceive('write')->andReturnUsing(function ($key, $body, $namespace) use (&$bodies) {
            $bodies[] = $body;

            return new StorageItem((string) Str::uuid(), $body, $namespace, time());
        });
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $driver));
        $compiler = Mockery::mock(EntryBodyCompiler::class);
        $compiler->shouldReceive('handles')->andReturnTrue();
        $compiler->shouldReceive('compile')->andReturn('export default () => null;');
        $this->app->instance(EntryBodyCompiler::class, $compiler);

        (new DocsSeeder)->run();

        return $bodies;
    }
}
