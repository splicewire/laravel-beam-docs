<?php

namespace Splicewire\Beam\Docs\Reconcile;

use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Docs\Registries\PackageDocsManifest;
use Splicewire\Beam\Ux\Disk\RegisterFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Throwable;

/**
 * Rows whose origin file went away (docs-feedback ticket 15): the inverse of `docs.unregistered`, which finds a file
 * with no row. Decided from provenance alone, no git:
 *
 * - a `disk:<relative>` row is vestigial when no declared HOST source (one with no `package`) holds `<relative>`;
 * - a `package:<vendor/name>` row is vestigial when that package declares at least one source and no file under any of
 *   them produces the row's `(namespace, slug)`, the importer's own key ({@see RegisterFromDisk::envelopeForPath()}).
 *
 * A file a source's `ignore` globs skip still counts as present: a row backed by an ignored file is not this finder's
 * business. A package with no declared source on this host is not judged (it is not installed, or its docs are off).
 * A `cms` or `pack:` row has no file and is never judged.
 *
 * Both the `docs.origin-missing` audit and the reconcile command read this, so they cannot disagree.
 */
class VestigialRows
{
    public function __construct(
        private RegisterFromDisk $disk,
    ) {}

    /** Whether any docs source is declared, so there is anything to judge a row by. */
    public function judged(): bool
    {
        return $this->sources() !== [];
    }

    /** @return list<VestigialRow> */
    public function find(): array
    {
        $table = (new BeamUxEntry)->getTable();
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'origin')) {
            return [];
        }

        $sources = $this->sources();
        $hostRoots = [];
        $packageSources = [];
        foreach ($sources as $source) {
            if ($source['package'] === null) {
                $hostRoots[] = $source['root'];
            } else {
                $packageSources[$source['package']][] = $source;
            }
        }
        if ($hostRoots === [] && $packageSources === []) {
            return [];
        }

        $backed = [];
        $marked = Schema::hasColumn($table, 'workflow_marking');
        $rows = [];
        foreach (BeamUxEntry::query()->whereNotNull('origin')->orderBy('slug')->cursor() as $entry) {
            $origin = (string) $entry->origin;
            $sourcesFor = [];
            if (str_starts_with($origin, Provenance::DISK_PREFIX)) {
                if ($hostRoots === []) {
                    continue;
                }
                $relative = ltrim(substr($origin, strlen(Provenance::DISK_PREFIX)), '/');
                if ($this->onDisk($hostRoots, $relative)) {
                    continue;
                }
                $candidates = array_map(fn (string $root) => $root.'/'.$relative, $hostRoots);
                $sourcesFor = array_values(array_filter($sources, fn (array $s) => $s['package'] === null));
            } elseif (str_starts_with($origin, Provenance::PACKAGE_PREFIX)) {
                $package = substr($origin, strlen(Provenance::PACKAGE_PREFIX));
                if (! isset($packageSources[$package])) {
                    continue;
                }
                $backed[$package] ??= $this->keys($packageSources[$package]);
                if (isset($backed[$package][$this->key($entry->namespace, (string) $entry->slug)])) {
                    continue;
                }
                $candidates = $this->candidatePaths($entry, $packageSources[$package]);
                $sourcesFor = $packageSources[$package];
            } else {
                continue;
            }

            $marking = $marked ? $entry->getAttribute('workflow_marking') : null;
            $rows[] = new VestigialRow(
                entry: $entry,
                published: $marking === null || $marking === BeamUxEntry::MARKING_PUBLISHED,
                marking: $marking,
                candidates: $candidates,
                sources: $sourcesFor,
            );
        }

        return $rows;
    }

    /**
     * Every declared source, normalized: an absolute, symlink-resolved root and its `package`, `type` and `ignore`.
     *
     * @return list<array{root: string, declared: string, package: ?string, type: ?string, ignore: list<string>}>
     */
    public function sources(): array
    {
        $out = [];
        foreach ((array) config('beam.docs.sources', []) as $source) {
            $source = (array) $source;
            $declared = (string) ($source['path'] ?? '');
            if ($declared === '') {
                continue;
            }
            $root = rtrim(str_starts_with($declared, '/') ? $declared : base_path($declared), '/');
            if (! is_dir($root)) {
                continue;
            }
            $out[] = [
                'root' => realpath($root) ?: $root,
                'declared' => $declared,
                'package' => isset($source['package']) && $source['package'] !== '' ? (string) $source['package'] : null,
                'type' => isset($source['type']) ? (string) $source['type'] : null,
                'ignore' => array_values(array_map('strval', (array) ($source['ignore'] ?? []))),
            ];
        }

        return $out;
    }

    /**
     * The `(namespace, slug)` key each file under these sources produces, by the importer's own envelope.
     *
     * @param  list<array{root: string}>  $sources
     * @return array<string, string> key => absolute path
     */
    public function keys(array $sources): array
    {
        $keys = [];
        foreach ($sources as $source) {
            foreach ($this->files($source['root']) as $absolute) {
                $relative = ltrim(substr($absolute, strlen($source['root'])), '/');
                $envelope = $this->disk->recognizes($relative) ? $this->disk->envelopeForPath($relative) : null;
                if ($envelope !== null) {
                    $keys[$this->key($envelope['namespace'], $envelope['slug'])] ??= $absolute;
                }
            }
        }

        return $keys;
    }

    /** The `(namespace, slug)` key a file under `$source` produces, or null when it is not a recognized body. */
    public function keyFor(string $absolute, array $source): ?string
    {
        $relative = ltrim(substr($absolute, strlen($source['root'])), '/');
        $envelope = $this->disk->recognizes($relative) ? $this->disk->envelopeForPath($relative) : null;

        return $envelope === null ? null : $this->key($envelope['namespace'], $envelope['slug']);
    }

    public function key(?string $namespace, string $slug): string
    {
        return ($namespace ?? '')."\0".$slug;
    }

    /** Whether a source is a registry-pages source: a package's `docs/registries/` directory. */
    public static function isRegistrySource(array $source): bool
    {
        return $source['package'] !== null && str_ends_with($source['root'], '/docs/registries');
    }

    /**
     * Where a package row's file would have been, under each of its package's sources: the inverse of the envelope
     * (`{namespace dots→slashes}[/{type}]/{slug}.{ext}`), with and without the type directory.
     *
     * @param  list<array{root: string}>  $sources
     * @return list<string>
     */
    private function candidatePaths(BeamUxEntry $entry, array $sources): array
    {
        $format = $this->scalar($entry->format);
        try {
            $extension = $this->disk->extensionFor($format);
        } catch (Throwable) {
            $extension = $format;
        }
        $type = $this->scalar($entry->type);
        $dir = $entry->namespace ? str_replace('.', '/', (string) $entry->namespace).'/' : '';
        $paths = [];
        foreach ($sources as $source) {
            if ($type !== '') {
                $paths[] = $source['root'].'/'.$dir.$type.'/'.$entry->slug.'.'.$extension;
            }
            $paths[] = $source['root'].'/'.$dir.$entry->slug.'.'.$extension;
        }

        return array_values(array_unique($paths));
    }

    private function scalar(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
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

    /** @return iterable<int, string> */
    private function files(string $root): iterable
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() !== PackageDocsManifest::FILENAME) {
                yield $file->getPathname();
            }
        }
    }
}
