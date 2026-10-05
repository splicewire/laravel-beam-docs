<?php

namespace Splicewire\Beam\Docs\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Throwable;

/**
 * `docs.diverged` (docs-walkthrough DOCS-06, rule DOC-4, ADR-0215): a seeded or imported row whose
 * stored body no longer equals what its origin asserted — i.e. the host edited it. It compares each
 * entry's `asserted_hash` (the title+body hash its `origin` last wrote) against the hash recomputed
 * from the STORED body, exactly as {@see Provenance::hash} took it, so a byte-identical row is silent
 * and a real edit is named.
 *
 * REPORT-ONLY, and a WARN rather than a FAIL: whether a host edits a page is a host-dependent fact,
 * not a defect, and the estate's rule is that a host-dependent condition never FAILs a shared gate.
 * The audit reads the stored body on every host as the host stands — never a render, never a re-seed —
 * and never mutates a row. The pristine re-assert (rewriting a stale PRISTINE row from its origin) is
 * the ADR-0215 follow-on and is deliberately not performed here; a `cms`-origin row is never judged.
 */
class DocsDivergedAudit implements DoctorAudit
{
    public const CHECK = 'docs.diverged';

    public function __construct(
        private StorageDriverResolver $drivers,
    ) {}

    public function run(): array
    {
        $table = (new BeamUxEntry)->getTable();

        if (! Schema::hasTable($table)) {
            return [Finding::inconclusive(self::CHECK, 'The entries table does not exist yet; migrate, then re-run.')];
        }

        if (! Schema::hasColumn($table, 'asserted_hash') || ! Schema::hasColumn($table, 'origin')) {
            return [Finding::inconclusive(self::CHECK, 'Provenance columns are not present yet; run the DOCS-06 migration, then re-run.')];
        }

        $checked = 0;
        $diverged = [];
        foreach (
            BeamUxEntry::query()
                ->whereNotNull('particle_id')
                ->whereNotNull('asserted_hash')
                ->whereNotNull('origin')
                ->cursor() as $entry
        ) {
            // Only an origin that ASSERTS content is judged for divergence: a package stub or a host
            // file. A `cms` row is authored in place and has no upstream to diverge from.
            if (! $this->asserts((string) $entry->origin)) {
                continue;
            }

            $body = $this->source($entry);
            if ($body === null) {
                continue;
            }

            $checked++;
            if (Provenance::hash($entry->title, $body) !== (string) $entry->asserted_hash) {
                $diverged[] = ($entry->namespace ? "{$entry->namespace}." : '')."{$entry->slug} ({$entry->origin})";
            }
        }

        if ($checked === 0) {
            return [Finding::inconclusive(self::CHECK, 'No seeded or imported row has both a stored body and an asserted hash to compare.')];
        }

        $counts = sprintf('%d checked · %d diverged', $checked, count($diverged));
        if ($diverged !== []) {
            return [Finding::warn(self::CHECK, "{$counts}. Edited since their origin last asserted them (re-assert is gated on OQ-D3): ".implode(', ', $diverged).'.')];
        }

        return [Finding::pass(self::CHECK, "{$counts}. Every seeded or imported row still matches what its origin asserted.")];
    }

    private function asserts(string $origin): bool
    {
        return str_starts_with($origin, Provenance::DISK_PREFIX)
            || str_starts_with($origin, Provenance::PACKAGE_PREFIX);
    }

    private function source(BeamUxEntry $entry): ?string
    {
        try {
            $item = $this->drivers->resolve($entry)->read((string) $entry->particle_id);

            return $item === null ? null : $entry->codec()->decode($item->body);
        } catch (Throwable) {
            return null;
        }
    }
}
