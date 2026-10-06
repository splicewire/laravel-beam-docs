<?php

namespace Splicewire\Beam\Docs\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Provenance\ProvenanceBackfill;

/**
 * Records the prior versions of this host's docs files that its live rows still hold (docs-walkthrough DOCS-06b), so the
 * provenance backfill can recognise them without git at run time. BOUNDED to the field (lead 08:33Z): for each unstamped
 * row whose disk file has changed since it was imported, it walks that file's git history and records only the version
 * equal to the row's stored body (its commit and the sha256 of the stored form). Without `--write` it prints and
 * compares; the tests never write (docs/conventions/regenerating-committed-artifacts.md).
 */
class ProvenanceHistoryCommand extends Command
{
    protected $signature = 'splicewire:beam:docs:provenance-history {--write : Write the history file instead of comparing}';

    protected $description = 'Record the prior docs-file versions that live rows still hold, for the provenance backfill.';

    public function handle(ProvenanceBackfill $backfill): int
    {
        $history = [];
        $stamped = Schema::hasColumn((new BeamUxEntry)->getTable(), 'origin');

        foreach ((array) config('beam.docs.sources', []) as $source) {
            $declared = rtrim((string) ($source['path'] ?? ''), '/');
            $files = $backfill->diskFiles([$source]);
            $rows = BeamUxEntry::query()->when($stamped, fn ($q) => $q->whereNull('origin'))->whereNotNull('particle_id')->get();

            foreach ($rows as $entry) {
                $file = $files[($entry->namespace ?? '').'|'.$entry->slug] ?? null;
                $stored = $file !== null ? $backfill->storedBody($entry) : null;
                if ($file === null || $stored === null) {
                    continue;
                }
                $codec = $entry->codec();
                if (Provenance::asStored($codec, (string) file_get_contents($file['absolute'])) === $stored) {
                    continue; // matches the current file: no history needed
                }
                $path = ltrim($declared.'/'.$file['relative'], '/');
                $log = Process::path(base_path())->run(['git', 'log', '--follow', '--format=%H', '--', $path]);
                foreach (array_filter(explode("\n", trim($log->output()))) as $commit) {
                    $show = Process::path(base_path())->run(['git', 'show', $commit.':'.$path]);
                    if ($show->successful() && Provenance::asStored($codec, $show->output()) === $stored) {
                        $history[$file['relative']][] = ['commit' => substr($commit, 0, 12), 'sha256' => hash('sha256', $stored)];

                        break;
                    }
                }
            }
        }

        ksort($history);
        $json = json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $target = (string) config('beam.docs.provenance_history', resource_path('beam-docs/provenance-history.json'));
        $this->line(sprintf('%d file(s) with a prior version a live row still holds.', count($history)));

        if (! $this->option('write')) {
            $current = is_file($target) ? (string) file_get_contents($target) : null;
            $this->line($current === $json ? 'History file up to date.' : 'History file differs. Re-run with --write.');

            return $current === $json ? self::SUCCESS : self::FAILURE;
        }

        @mkdir(dirname($target), 0755, true);
        file_put_contents($target, $json);
        $this->info("Written: {$target}");

        return self::SUCCESS;
    }
}
