<?php

namespace Splicewire\Beam\Docs\Tests;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * DOCS-06b at the package that owns the docs root: www's live docs root still holds beam-ux's OLD index stub
 * (laravel-beam-ux@9f5df5a, "This site documents **itself**…", "Seeded by…"), seeded before the provenance migration and
 * before the stub moved here. The shipped prior template lets the backfill recognise it as this package's, so the next
 * docs seed re-asserts it to the current brand-titled stub. An edited copy is left alone.
 */
class ProvenanceBackfillDocsTest extends TestCase
{
    /** @var array<string, StorageItem> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        config(['beam.brand.name' => 'Acme', 'beam.ux.compile.disk' => 'docs-artifacts', 'beam.docs.sources' => []]);
        Storage::fake('docs-artifacts');

        $driver = Mockery::mock(StorageDriver::class);
        $driver->shouldReceive('write')->andReturnUsing(function ($key, $body, $namespace) {
            $key = $key !== '' ? $key : 'p'.(count($this->items) + 1);

            return $this->items[$key] = new StorageItem($key, $body, $namespace, time());
        });
        $driver->shouldReceive('read')->andReturnUsing(fn ($key) => $this->items[$key] ?? null);
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $driver));
        $compiler = Mockery::mock(EntryBodyCompiler::class);
        $compiler->shouldReceive('handles')->andReturnTrue();
        $compiler->shouldReceive('compile')->andReturn('export default () => null;');
        $this->app->instance(EntryBodyCompiler::class, $compiler);
    }

    public function test_the_old_beam_ux_docs_root_is_stamped_this_packages_and_the_next_seed_brings_it_current(): void
    {
        $root = $this->legacyRoot((string) file_get_contents(dirname(__DIR__).'/stubs/docs/history/index@laravel-beam-ux-9f5df5a.mdx'));
        $this->migrate();

        $this->artisan('splicewire:beam:docs:provenance-backfill', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull($root->fresh()->origin, 'a dry run writes nothing');

        $this->artisan('splicewire:beam:docs:provenance-backfill')->assertSuccessful();
        $this->assertSame(Provenance::package('splicewire/laravel-beam-docs'), $root->fresh()->origin);

        (new DocsSeeder)->run();

        $body = $this->body($root->fresh());
        $this->assertStringContainsString('# Acme documentation', $body);
        $this->assertStringNotContainsString('Seeded by', $body);
        $this->assertSame('Acme documentation', $root->fresh()->title);
    }

    public function test_an_edited_copy_of_the_old_root_stays_unknown_and_keeps_its_edit(): void
    {
        $old = (string) file_get_contents(dirname(__DIR__).'/stubs/docs/history/index@laravel-beam-ux-9f5df5a.mdx');
        $edited = str_replace('re-root the whole', 're-root all of the', $old);
        $root = $this->legacyRoot($edited);
        $this->migrate();

        $this->artisan('splicewire:beam:docs:provenance-backfill')->assertSuccessful();
        $this->assertNull($root->fresh()->origin);

        (new DocsSeeder)->run();
        $this->assertStringContainsString('re-root all of the', $this->body($root->fresh()));
    }

    private function legacyRoot(string $source): BeamUxEntry
    {
        $row = BeamUxEntry::create(['slug' => 'docs', 'namespace' => null, 'type' => 'page', 'format' => 'mdx', 'title' => 'Documentation',
            'parent_id' => BeamUxEntry::rootFor(BeamUxEntry::REALM_SITE)->getKey(), 'segment' => '/docs', 'requirements' => ['beam-docs']]);
        $written = app(StorageDriverResolver::class)->resolve($row)->write('', $row->codec()->encode($source), $row->namespace);
        BeamUxEntry::query()->whereKey($row->getKey())->update(['particle_id' => $written->key]);

        return $row->fresh();
    }

    private function migrate(): void
    {
        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/add_provenance_to_beam_ux_entries_table.php.stub')->up();
    }

    private function body(BeamUxEntry $entry): string
    {
        return $entry->codec()->decode($this->items[$entry->particle_id]->body);
    }
}
