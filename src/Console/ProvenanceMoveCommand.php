<?php

namespace Splicewire\Beam\Docs\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Throwable;

/**
 * Converge the live rows when the Splicewire docs bundle MOVES from the flagship disk into the
 * `splicewire/tower` package (docs-walkthrough DOCS-15 MR7; lead change B, 2026-10-06 14:23Z).
 *
 * The flagship's live docs rows are stamped with a scan-root-relative disk origin `disk:docs/…` (the docs
 * content is scanned from the host's `resources/js/content` root). Moving the files into the package
 * changes the origin to `package:splicewire/tower` at the SAME `(namespace, slug)` — the adjusted DOC-2
 * layout gives the tower bundle its own root `resources/docs-bundle/docs/**`, so the namespace stays
 * `docs`. The ordinary seed
 * can NOT make that change: {@see \Splicewire\Beam\Ux\Provenance\Reasserter} refuses to re-assert a
 * WEAKER incoming origin over a stronger incumbent (DOC-5: disk outranks package), so a package seed
 * would leave the old disk rows untouched — now orphaned (their disk file is gone) and no longer
 * re-asserting — or, on coordinate drift, create duplicate package rows beside them.
 *
 * This one-time, deliberate migration re-stamps each PRISTINE row under the old disk source to the
 * package origin IN PLACE (same `(namespace, slug)`, same `asserted_hash` — the body is unchanged by a
 * `git mv`), so the create-once {@see \Splicewire\Beam\Docs\Seed\DocsSourcesSeeder} recognises the
 * package's files as already-rowed (no duplicate) and the rows keep re-asserting from the package (no
 * orphan). An EDITED row (stored body ≠ `asserted_hash`) is left exactly as it is — kept and reported
 * by `docs.diverged`, never silently moved. `--dry-run` prints the per-row plan and writes nothing: it
 * is the diff the integrator judges against a `pg_dump` restore point before the live `--write`.
 */
class ProvenanceMoveCommand extends Command
{
    protected $signature = 'splicewire:beam:docs:provenance-move {--dry-run : Print the per-row plan; write nothing}';

    protected $description = 'Re-stamp pristine docs rows from the moved disk source to the splicewire/tower package origin, in place.';

    public function handle(StorageDriverResolver $drivers): int
    {
        $move = (array) config('beam.docs.move', []);
        $from = trim((string) ($move['from'] ?? ''), '/');
        $toPackage = (string) ($move['to_package'] ?? '');

        if ($from === '' || $toPackage === '') {
            $this->error('Nothing to move: set beam.docs.move.from (the old disk source) and beam.docs.move.to_package (the receiving package).');

            return self::FAILURE;
        }

        $fromOrigin = Provenance::disk($from);          // e.g. disk:resources/js/content/docs
        $packageOrigin = Provenance::package($toPackage); // e.g. package:splicewire/tower

        $rows = BeamUxEntry::query()
            ->where(fn ($q) => $q->where('origin', $fromOrigin)->orWhere('origin', 'like', $fromOrigin.'/%'))
            ->get();

        $plan = [];
        $restampable = [];
        foreach ($rows as $row) {
            $pristine = $this->isPristine($row, $drivers);
            $plan[] = [$row->namespace ?? '', $row->slug, (string) $row->origin, $pristine ? 're-stamp → '.$packageOrigin : 'left (edited; docs.diverged)'];
            if ($pristine) {
                $restampable[] = $row;
            }
        }

        $this->table(['namespace', 'slug', 'origin', 'plan'], $plan);
        $this->line(sprintf('%d rows under %s · %d pristine (re-stamp) · %d edited (left).', count($rows), $fromOrigin, count($restampable), count($rows) - count($restampable)));

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        foreach ($restampable as $row) {
            // Body and title are unchanged by a git mv, so asserted_hash moves with the row untouched;
            // only the origin coordinate changes. Placement is site-owned and never touched here.
            $row->origin = $packageOrigin;
            $row->save();
        }

        $this->info(count($restampable).' rows re-stamped to '.$packageOrigin.'.');

        return self::SUCCESS;
    }

    /** Pristine == the stored body still equals what the origin asserted (mirrors Reasserter's test). */
    private function isPristine(BeamUxEntry $row, StorageDriverResolver $drivers): bool
    {
        if ($row->particle_id === null || $row->asserted_hash === null) {
            return false;
        }

        try {
            $item = $drivers->resolve($row)->read((string) $row->particle_id);
        } catch (Throwable) {
            return false;
        }

        if ($item === null) {
            return false;
        }

        $current = $row->codec()->decode($item->body);

        return Provenance::hash($row->title, $current) === (string) $row->asserted_hash;
    }
}
