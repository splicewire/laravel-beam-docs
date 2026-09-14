<?php

namespace Splicewire\Beam\Docs\Tests;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

class DocsSeedTest extends TestCase
{
    public function test_fresh_docs_seed_compiles_bodies_and_reseeding_preserves_authored_rows(): void
    {
        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        Storage::fake('docs-artifacts');
        config(['beam.ux.compile.disk' => 'docs-artifacts', 'beam.docs.segment' => '/handbook']);
        $sources = [];
        $driver = Mockery::mock(StorageDriver::class);
        $driver->shouldReceive('write')->twice()->andReturnUsing(function ($key, $body, $namespace) use (&$sources) {
            $key = (string) Str::uuid();
            $sources[$key] = $body;

            return new StorageItem($key, $body, $namespace, time());
        });
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $driver));
        $compiler = Mockery::mock(EntryBodyCompiler::class);
        $compiler->shouldReceive('handles')->andReturnTrue();
        $compiled = [];
        $compiler->shouldReceive('compile')->twice()->andReturnUsing(function ($entry, $body) use (&$compiled) {
            $compiled[$entry->slug] = $body;

            return 'export default () => null;';
        });
        $this->app->instance(EntryBodyCompiler::class, $compiler);
        (new DocsSeeder)->run();
        $root = BeamUxEntry::where('slug', 'docs')->sole();
        $api = BeamUxEntry::where('slug', 'docs-api')->sole();
        $this->assertSame('/handbook', $root->segment);
        $this->assertSame(['beam-docs'], $root->requirements);
        $this->assertSame($root->id, $api->parent_id);
        $this->assertStringContainsString('specUrl="/beam/openapi.yaml"', $compiled['docs-api']);
        $this->assertTrue(app(EntryArtifactStore::class)->has($root));
        $this->assertTrue(app(EntryArtifactStore::class)->has($api));
        $root->update(['title' => 'Edited handbook', 'segment' => '/edited', 'access' => ['auth']]);
        (new DocsSeeder)->run();
        $this->assertSame('Edited handbook', $root->fresh()->title);
        $this->assertSame('/edited', $root->fresh()->segment);
        $this->assertSame(['auth'], $root->fresh()->access);
        $this->assertCount(2, $sources);
    }
}
