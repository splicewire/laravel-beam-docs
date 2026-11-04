<?php

namespace Splicewire\Beam\Docs\Doctor;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\DoctorStatus;
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
 *
 * Two kinds of row are neither pristine nor diverged, and each is counted on its own line (registry-docs ticket 16):
 *
 * - **Unreadable**: this host cannot print the stored body (a blocks body with no resolvable MDX lens, ticket 14). The
 *   row is unjudged, so the check is INCONCLUSIVE and never PASS, naming the rows and the first diagnostic.
 * - **Origin missing**: a `disk:` row whose file is under no declared host docs source (`beam.docs.sources`). Its words were
 *   never edited; its origin went away (www's `engines`). It is a WARN while the row is published, and informational
 *   once it is unpublished (a workflow marking other than `published`). Judged only where sources are declared.
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
        $unreadable = [];
        $firstWhy = null;
        $missing = [];
        $missingPublished = 0;
        $roots = $this->sourceRoots();
        $marked = Schema::hasColumn($table, 'workflow_marking');
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

            $name = ($entry->namespace ? "{$entry->namespace}." : '').$entry->slug;

            if ($roots !== null && ($relative = $this->diskPath((string) $entry->origin)) !== null && ! $this->onDisk($roots, $relative)) {
                $marking = $marked ? $entry->getAttribute('workflow_marking') : null;
                $published = $marking === null || $marking === BeamUxEntry::MARKING_PUBLISHED;
                $missingPublished += $published ? 1 : 0;
                $missing[] = "{$name} ({$entry->origin}, ".($published ? 'published' : "unpublished: {$marking}").')';

                continue;
            }

            [$body, $why] = $this->source($entry);
            if ($why !== null) {
                $unreadable[] = "{$name} ({$entry->origin})";
                $firstWhy ??= $why;

                continue;
            }
            if ($body === null) {
                continue;
            }

            $checked++;
            if (Provenance::hash($entry->title, $body) !== (string) $entry->asserted_hash) {
                $diverged[] = "{$name} ({$entry->origin})";
            }
        }

        if ($checked === 0 && $unreadable === [] && $missing === []) {
            return [Finding::inconclusive(self::CHECK, 'No seeded or imported row has both a stored body and an asserted hash to compare.')];
        }

        $lines = [sprintf('%d checked · %d diverged', $checked, count($diverged)).($diverged !== []
            ? '. Edited since their origin last asserted them, so a re-assert keeps them as they are: '.implode(', ', $diverged).'.'
            : ($unreadable === [] ? '. Every seeded or imported row this host can read still matches what its origin asserted.' : '.'))];
        if ($unreadable !== []) {
            $lines[] = sprintf('%d stored body unreadable on this host, so neither pristine nor edited and never re-asserted until the host can print them (first: %s): %s.', count($unreadable), $firstWhy, implode(', ', $unreadable));
        }
        if ($missing !== []) {
            $lines[] = sprintf('%d origin missing (no file under the declared docs sources): %s. Their words were never edited; their origin went away.', count($missing), implode(', ', $missing))
                .($missingPublished > 0 ? ' A published one still serves words no file asserts; unpublish it or give it a file.' : ' None is published.');
        }
        $detail = implode("\n", $lines);

        if ($unreadable !== []) {
            // Inconclusive, but never a PASS: rows went unjudged (registry-docs ticket 14 read "4 checked · 0 diverged" over 47).
            return [new Finding(DoctorStatus::Warn, self::CHECK, $detail, conclusive: false)];
        }
        if ($diverged !== [] || $missingPublished > 0) {
            return [Finding::warn(self::CHECK, $detail)];
        }

        return [Finding::pass(self::CHECK, $detail)];
    }

    /**
     * Every declared HOST docs source root: one with no `package`, the only kind that stamps `disk:` (a source that names
     * its package stamps `package:`). Null where the host declares none, so there is nothing to judge a `disk:` row by.
     *
     * @return ?list<string>
     */
    private function sourceRoots(): ?array
    {
        $roots = [];
        foreach ((array) config('beam.docs.sources', []) as $source) {
            $source = (array) $source;
            $declared = (string) ($source['path'] ?? '');
            if ($declared !== '' && (string) ($source['package'] ?? '') === '') {
                $roots[] = rtrim(str_starts_with($declared, '/') ? $declared : base_path($declared), '/');
            }
        }

        return $roots === [] ? null : $roots;
    }

    private function diskPath(string $origin): ?string
    {
        return str_starts_with($origin, Provenance::DISK_PREFIX) ? ltrim(substr($origin, strlen(Provenance::DISK_PREFIX)), '/') : null;
    }

    /** @param  list<string>  $roots */
    private function onDisk(array $roots, string $relative): bool
    {
        foreach ($roots as $root) {
            if (is_file($root.'/'.$relative)) {
                return true;
            }
        }

        return false;
    }

    private function asserts(string $origin): bool
    {
        return str_starts_with($origin, Provenance::DISK_PREFIX)
            || str_starts_with($origin, Provenance::PACKAGE_PREFIX);
    }

    /** @return array{0: ?string, 1: ?string} the stored body, or null and why it could not be read (a lens's own words) */
    private function source(BeamUxEntry $entry): array
    {
        try {
            $item = $this->drivers->resolve($entry)->read((string) $entry->particle_id);

            return $item === null ? [null, null] : [$entry->codec()->decode($item->body), null];
        } catch (Throwable $e) {
            $first = $e instanceof ValidationException ? collect($e->errors())->flatten()->first() : null;

            return [null, trim((string) ($first ?? $e->getMessage())) ?: $e::class];
        }
    }
}
