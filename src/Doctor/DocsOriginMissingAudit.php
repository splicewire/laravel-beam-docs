<?php

namespace Splicewire\Beam\Docs\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Docs\Reconcile\DocsReconciler;
use Splicewire\Beam\Docs\Reconcile\ReconcileItem;
use Splicewire\Beam\Docs\Reconcile\VestigialRows;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `docs.origin-missing` (docs-feedback ticket 15): the inverse of `docs.unregistered`. That check finds a file with no
 * row; this one finds a seeded row whose file is under none of its origin's declared docs sources
 * ({@see VestigialRows}): a `disk:` row whose file is gone, or a `package:` row no
 * file of its package produces any more. Its words were never edited; its origin went away.
 *
 * A WARN while such a row is still published: it serves words no file asserts, at a URL that should have moved or
 * gone. Each is named with the reconcile plan's answer (redirect to its renamed file's row, take down, or report with
 * the candidate), and `splicewire:beam:docs:reconcile --apply` acts on it. A row already taken down is kept for its
 * history and is informational, so a reconciled host passes.
 *
 * Report-only: it reads rows and git history, and writes nothing.
 */
class DocsOriginMissingAudit implements DoctorAudit
{
    public const CHECK = 'docs.origin-missing';

    public function __construct(private DocsReconciler $reconciler) {}

    public function run(): array
    {
        if ((array) config('beam.docs.sources', []) === []) {
            return [Finding::inconclusive(self::CHECK, 'No docs source is declared (beam.docs.sources), so no row has an origin to miss.')];
        }
        $table = (new BeamUxEntry)->getTable();
        if (! Schema::hasTable($table)) {
            return [Finding::inconclusive(self::CHECK, 'The entries table does not exist yet; migrate, then re-run.')];
        }
        if (! Schema::hasColumn($table, 'origin')) {
            return [Finding::inconclusive(self::CHECK, 'Provenance columns are not present yet; run the DOCS-06 migration, then re-run.')];
        }

        $plan = $this->reconciler->plan();
        $live = array_values(array_filter($plan, fn (ReconcileItem $item) => $item->action !== DocsReconciler::DOWN));
        $down = array_values(array_filter($plan, fn (ReconcileItem $item) => $item->action === DocsReconciler::DOWN));

        $lines = [sprintf('%d published · %d taken down', count($live), count($down)).($live === [] && $down === []
            ? '. Every seeded row has its file under its origin\'s declared docs sources.'
            : '.')];
        foreach ($live as $item) {
            $lines[] = $item->describe();
        }
        if ($down !== []) {
            $lines[] = 'Taken down, kept for their history: '.implode(', ', array_map(fn (ReconcileItem $item) => $item->row->describe(), $down)).'.';
        }
        if ($live !== []) {
            $lines[] = 'Each published one still serves words no file asserts. Run `splicewire:beam:docs:reconcile` to see the plan, then --apply --actor=<user> to take them down.';

            return [Finding::warn(self::CHECK, implode("\n", $lines))];
        }

        return [Finding::pass(self::CHECK, implode("\n", $lines))];
    }
}
