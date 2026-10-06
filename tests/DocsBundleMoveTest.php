<?php

namespace Splicewire\Beam\Docs\Tests;

use Mockery;
use Illuminate\Support\Facades\Storage;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * DOCS-15 MR7 — the move's live convergence (docs-walkthrough DOCS-15; lead change B, 2026-10-06 14:23Z).
 *
 * The Splicewire docs bundle moves off the flagship disk (the docs content is scanned from the host's
 * `resources/js/content` root, so its rows carry a scan-root-relative origin `disk:docs/…`) and into the
 * `splicewire/tower` package (origin `package:splicewire/tower`). Under the adjusted DOC-2 layout the tower
 * bundle keeps the `docs` namespace (its own root `resources/docs-bundle/docs/**`), so the coordinate is
 * byte-identical and the move RE-STAMPS each pristine row at its old coordinate to the package origin IN
 * PLACE — exact-hash matched, same `(namespace, slug)` — so the create-once
 * {@see DocsSourcesSeeder} recognises the package's files as already-rowed (no duplicate) and the rows keep
 * re-asserting (no orphaned disk origin pointing at a deleted file). An EDITED row is never re-stamped: it
 * keeps its edit and `docs.diverged` reports it (covered by DocsDivergedAuditTest).
 *
 * Red-first: the `splicewire:beam:docs:provenance-move` command does not exist yet.
 */
class DocsBundleMoveTest extends TestCase
{
    // The scan-root-relative prefix the flagship's docs rows actually carry (scan root resources/js/content).
    private const FROM = 'docs';
    private const TO_PACKAGE = 'splicewire/tower';

    /** @var array<string, StorageItem> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        (require $ux.'/database/migrations/shared/add_provenance_to_beam_ux_entries_table.php.stub')->up();

        config([
            'beam.brand.name' => 'Acme',
            'beam.ux.compile.disk' => 'docs-artifacts',
            'beam.docs.sources' => [],
            // The move the command converges: old flagship disk bundle -> the tower package.
            'beam.docs.move' => ['from' => self::FROM, 'to_package' => self::TO_PACKAGE],
        ]);
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

    public function test_a_moved_pristine_disk_row_is_restamped_to_the_package_in_place(): void
    {
        $relative = self::FROM.'/page/operate/install.mdx';
        $row = $this->diskRow('operate-install', $relative, "# Install\n\nRun `composer setup`.");
        $before = BeamUxEntry::query()->count();

        $this->artisan('splicewire:beam:docs:provenance-move', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(Provenance::disk($relative), $row->fresh()->origin, 'a dry run writes nothing');

        $this->artisan('splicewire:beam:docs:provenance-move')->assertSuccessful();

        $fresh = $row->fresh();
        $this->assertSame($before, BeamUxEntry::query()->count(), 'no duplicate row is created by the move');
        $this->assertSame(Provenance::package(self::TO_PACKAGE), $fresh->origin, 'the row is re-stamped to the package origin in place');
        $this->assertSame($row->asserted_hash, $fresh->asserted_hash, 'a pristine body re-stamps without changing the asserted hash');

        // And a subsequent seed neither duplicates nor orphans the moved row: exactly one row remains at
        // its coordinate, still carrying the package origin (seeder baseline rows are not the moved row).
        (new DocsSeeder)->run();
        $atCoordinate = BeamUxEntry::query()->where('slug', 'operate-install')->get();
        $this->assertCount(1, $atCoordinate, 'the next seed neither duplicates nor orphans the moved row');
        $this->assertSame(Provenance::package(self::TO_PACKAGE), $atCoordinate->first()->origin);
    }

    public function test_an_edited_moved_row_is_kept_and_not_restamped(): void
    {
        $relative = self::FROM.'/page/operate/backup-and-restore.mdx';
        $row = $this->diskRow('operate-backup', $relative, "# Backup and restore\n\nCentral and per-tenant schemas.");

        // The host edited the body after it was stamped: its stored hash now differs from asserted_hash.
        $this->editBody($row, "# Backup and restore\n\nCentral and per-tenant schemas, nightly.");
        $this->assertNotSame($row->asserted_hash, Provenance::hash($row->title, $this->storedBody($row->fresh())), 'the row reads as edited');

        $this->artisan('splicewire:beam:docs:provenance-move')->assertSuccessful();

        $fresh = $row->fresh();
        $this->assertSame(Provenance::disk($relative), $fresh->origin, 'an edited row is not re-stamped to the package');
        $this->assertStringContainsString('nightly', $this->storedBody($fresh), 'the edit survives the move');
    }

    private function diskRow(string $slug, string $relative, string $source): BeamUxEntry
    {
        $title = 'Doc '.$slug;
        $row = BeamUxEntry::create(array_merge([
            'slug' => $slug, 'namespace' => null, 'type' => 'page', 'format' => 'mdx', 'title' => $title,
            'parent_id' => BeamUxEntry::rootFor(BeamUxEntry::REALM_SITE)->getKey(), 'segment' => '/docs/'.$slug,
            'requirements' => ['beam-docs'],
        ], Provenance::stamp(Provenance::disk($relative), $title, $source, $this->codecFor('mdx'))));

        $written = app(StorageDriverResolver::class)->resolve($row)->write('', $row->codec()->encode($source), $row->namespace);
        BeamUxEntry::query()->whereKey($row->getKey())->update(['particle_id' => $written->key]);

        return $row->fresh();
    }

    private function editBody(BeamUxEntry $row, string $newSource): void
    {
        $this->items[$row->particle_id] = new StorageItem($row->particle_id, $row->codec()->encode($newSource), $row->namespace, time());
    }

    private function storedBody(BeamUxEntry $row): string
    {
        return $row->codec()->decode($this->items[$row->particle_id]->body);
    }

    private function codecFor(string $format)
    {
        return BeamUxEntry::make(['format' => $format])->codec();
    }
}
