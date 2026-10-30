<?php

namespace Splicewire\Beam\Docs\Doctor;

use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Disk\RegisterFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Throwable;

/** Reads authored bodies, never renders, imports, seeds or writes them. */
class DocsLocalEnvAudit implements DoctorAudit
{
    public const CHECK = 'docs.local-env';

    public function __construct(private StorageDriverResolver $drivers, private RegisterFromDisk $disk) {}

    public function run(): array
    {
        $bodies = 0;
        $files = 0;
        $failures = [];
        $warnings = [];
        $tokens = (array) config('beam.docs.lint.forbidden', []);
        $scan = static function (string $label, string $body) use ($tokens, &$failures, &$warnings): void {
            foreach ($tokens as $token => $severity) {
                if (! is_string($token) || $token === '') {
                    continue;
                }
                $count = substr_count($body, $token);
                if ($count === 0) {
                    continue;
                }
                $detail = "{$label} → {$token} ×{$count}";
                if ($severity === 'warn') {
                    $warnings[] = $detail;
                } else {
                    $failures[] = $detail;
                }
            }
        };

        if (Schema::hasTable((new BeamUxEntry)->getTable())) {
            foreach (BeamUxEntry::query()->whereNotNull('particle_id')->cursor() as $entry) {
                $label = ($entry->namespace ? "{$entry->namespace}." : '').$entry->slug;
                try {
                    $item = $this->drivers->resolve($entry)->read((string) $entry->particle_id);
                    if ($item === null) {
                        $warnings[] = "{$label}: stored body unavailable";
                        continue;
                    }
                    $scan($label, $entry->codec()->decode($item->body));
                    $bodies++;
                } catch (Throwable) {
                    // Do not echo storage errors or body contents (they may contain credentials).
                    $warnings[] = "{$label}: stored body unreadable";
                }
            }
        }

        foreach ((array) config('beam.docs.sources', []) as $source) {
            $path = (string) ($source['path'] ?? '');
            $root = str_starts_with($path, '/') ? $path : base_path($path);
            if ($path === '' || ! is_dir($root)) {
                $warnings[] = "{$path}: declared source missing";
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    $relative = substr($file->getPathname(), strlen(rtrim($root, '/')) + 1);
                    if (! $file->isFile() || $file->isLink() || ! $this->disk->recognizes($relative)
                        || RegisterEntriesFromDisk::ignores((array) ($source['ignore'] ?? []), $relative)) {
                        continue;
                    }
                    $body = file_get_contents($file->getPathname());
                    if ($body === false) {
                        $warnings[] = "{$path}/{$relative}: source unreadable";
                        continue;
                    }
                    $scan("{$path}/{$relative}", $body);
                    $files++;
                }
            } catch (Throwable) {
                $warnings[] = "{$path}: source scan incomplete";
            }
        }

        $detail = "{$bodies} stored bodies · {$files} source files. This audit cannot see the starters' repo markdown outside beam.docs.sources.";
        if ($failures !== []) {
            return [Finding::fail(self::CHECK, $detail.' Forbidden local environment tokens: '.implode(', ', $failures).'.'.($warnings ? ' Warnings: '.implode(', ', $warnings).'.' : ''))];
        }
        if ($warnings !== []) {
            return [Finding::warn(self::CHECK, $detail.' '.implode(', ', $warnings).'.')];
        }
        if ($bodies === 0) {
            return [Finding::inconclusive(self::CHECK, $detail.' No stored bodies were available; disk results do not establish stored content cleanliness.')];
        }

        return [Finding::pass(self::CHECK, $detail.' No configured local environment tokens found.')];
    }
}
