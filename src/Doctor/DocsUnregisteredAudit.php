<?php

namespace Splicewire\Beam\Docs\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Disk\RegisterFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Type\UxType;

/**
 * `docs.unregistered` (docs-walkthrough DOCS-01, rule DOC-3): every content file under a declared docs source
 * (`beam.docs.sources`) has a row. A file with no row is a FAIL naming the path: the host declared the root, so a
 * missing row is the host contradicting its own config.
 *
 * It iterates FILES, the denominator `UpdateFromNewer` lacks (it walks rows), through the importer's own read-only
 * plan ({@see RegisterEntriesFromDisk::plan()}): the same files, envelope and `(namespace, slug)` key the import uses,
 * so the audit and `register-from-disk` cannot disagree. Output: `matched · unregistered · residual`, where residual
 * counts rows in the namespaces a source's files produce that no file backs (reported, not failed: a row may outlive
 * its file by design until DOCS-05's seed chain decides).
 *
 * The seed step that materializes the sources is DOCS-05; until it lands a host that declares a source FAILs here.
 */
class DocsUnregisteredAudit implements DoctorAudit
{
    public const CHECK = 'docs.unregistered';

    public function __construct(
        private RegisterEntriesFromDisk $importer,
        private RegisterFromDisk $disk,
    ) {}

    public function run(): array
    {
        $sources = (array) config('beam.docs.sources', []);
        if ($sources === []) {
            return [Finding::inconclusive(self::CHECK, 'No docs source is declared (beam.docs.sources), so there are no files to account for.')];
        }
        if (! Schema::hasTable((new BeamUxEntry)->getTable())) {
            return [Finding::inconclusive(self::CHECK, 'The entries table does not exist yet; migrate, then re-run.')];
        }

        $matched = 0;
        $residual = 0;
        $unregistered = [];
        $missing = [];
        foreach ($sources as $source) {
            $root = $this->absolute((string) ($source['path'] ?? ''));
            if (! is_dir($root)) {
                $missing[] = (string) ($source['path'] ?? '');

                continue;
            }
            $plan = $this->importer->plan($root, isset($source['type']) ? UxType::tryFrom((string) $source['type']) : null);
            $matched += count($plan['matched']);
            foreach ($plan['unregistered'] as $relative) {
                $unregistered[] = $this->display($root, $relative);
            }
            $residual += $this->residual([...$plan['matched'], ...$plan['unregistered']]);
        }

        $counts = sprintf('%d matched · %d unregistered · residual %d', $matched, count($unregistered), $residual);
        if ($missing !== []) {
            return [Finding::fail(self::CHECK, "{$counts}. Declared source(s) missing on disk: ".implode(', ', $missing).'.')];
        }
        if ($unregistered !== []) {
            return [Finding::fail(self::CHECK, "{$counts}. Files under a declared docs source with no entry row: ".implode(', ', $unregistered).'.')];
        }

        return [Finding::pass(self::CHECK, "{$counts}. Every file under a declared docs source has a row.")];
    }

    /** Rows in the namespaces these files produce that no file backs. The root (null) namespace is not judged. */
    private function residual(array $relatives): int
    {
        $keys = [];
        $namespaces = [];
        foreach ($relatives as $relative) {
            $envelope = $this->disk->envelopeForPath($relative);
            if ($envelope === null || $envelope['namespace'] === null) {
                continue;
            }
            $namespaces[$envelope['namespace']] = true;
            $keys[$envelope['namespace']."\0".$envelope['slug']] = true;
        }
        if ($namespaces === []) {
            return 0;
        }

        return BeamUxEntry::query()
            ->whereIn('namespace', array_keys($namespaces))
            ->get(['namespace', 'slug'])
            ->reject(fn (BeamUxEntry $row) => isset($keys[$row->namespace."\0".$row->slug]))
            ->count();
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /** The file as the operator declared it: relative to the host's base path when it lies under it. */
    private function display(string $root, string $relative): string
    {
        $full = rtrim($root, '/').'/'.$relative;
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($full, $base) ? substr($full, strlen($base)) : $full;
    }
}
