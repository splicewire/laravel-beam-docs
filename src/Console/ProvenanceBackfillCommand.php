<?php

namespace Splicewire\Beam\Docs\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Ux\Provenance\ProvenanceBackfill;

/**
 * The one-time provenance backfill over this host's docs (docs-walkthrough DOCS-06b): stamps each pre-migration row whose
 * stored body is exactly its disk file (now, or a version in `beam.docs.provenance_history`) or a registered package
 * template, so the next seed re-asserts it. `--dry-run` prints the per-row plan and writes nothing.
 */
class ProvenanceBackfillCommand extends Command
{
    protected $signature = 'splicewire:beam:docs:provenance-backfill {--dry-run : Print what would be stamped; write nothing}';

    protected $description = 'Stamp origin and asserted_hash on pre-migration docs rows whose stored body matches a known source exactly.';

    public function handle(ProvenanceBackfill $backfill): int
    {
        $plan = $backfill->plan((array) config('beam.docs.sources', []), self::history());

        $this->table(['slug', 'namespace', 'origin', 'via'], array_map(fn (array $row): array => [
            $row['slug'], $row['namespace'] ?? '', $row['origin'] ?? '(unknown: left alone)', $row['via'],
        ], $plan));

        $matched = count(array_filter($plan, fn (array $row): bool => $row['origin'] !== null));
        $this->line(sprintf('%d unstamped rows · %d match a known source · %d stay unknown.', count($plan), $matched, count($plan) - $matched));

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing written.');

            return self::SUCCESS;
        }

        $this->info($backfill->apply($plan).' rows stamped.');

        return self::SUCCESS;
    }

    /**
     * The host's recorded prior disk versions: relative path => sha256 of each as stored (written by
     * `splicewire:beam:docs:provenance-history --write`).
     *
     * @return array<string, list<string>>
     */
    public static function history(): array
    {
        $path = (string) config('beam.docs.provenance_history', resource_path('beam-docs/provenance-history.json'));
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
        $out = [];
        foreach ((array) $decoded as $relative => $versions) {
            $out[(string) $relative] = array_values(array_map(fn (array $v): string => (string) $v['sha256'], (array) $versions));
        }

        return $out;
    }
}
