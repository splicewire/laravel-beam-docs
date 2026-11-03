<?php

namespace Splicewire\Beam\Docs\Registries;

use ReflectionClass;
use Rushing\Popcorn\Laravel\Baking\DeclaredRegistryScan;
use Rushing\Popcorn\Registries\Docs\DeclaredRegistryRoots;
use Symfony\Component\Finder\Finder;

/**
 * The `#[IsRegistry]` declaration census the catalog validates against (registry-docs ticket 01,
 * amended ruling 3): the static source scan, never any one host's bake or runtime index — one
 * host's bake lacks roots the product documents (www is missing 39 of the flagship's), and dead
 * roots can only be detected against what is DECLARED. Delegates to popcorn's
 * {@see DeclaredRegistryScan}, which already skips the empty root structurally (the index describes
 * itself; `''` is not a legal key) and honours `popcorn.bake.paths` — the seam a spine host or a
 * test harness uses to widen the scan beyond this process's own install.
 */
class RegistryCensus
{
    public function __construct(private DeclaredRegistryScan $scan) {}

    /** @return array<string, array{class: class-string, file: string}> root => its declaring class and file, root-sorted */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->scan->run()['map'] as $root => $row) {
            $entries[$root] = [
                'class' => $row['class'],
                'file' => (string) (new ReflectionClass($row['class']))->getFileName(),
            ];
        }

        ksort($entries);

        return $entries;
    }

    /**
     * The declarations of family packages this process does NOT install — the spine host's
     * `beam.docs.registries.packages` rows — so the catalog's census is the estate's, not this
     * host's (one host's bake is never the estate census, R4).
     *
     * Each package's `#[IsRegistry]` classes are reached through a scoped PSR-4 autoloader built
     * from that package's own `composer.json` `autoload.psr-4` (never its `vendor/autoload.php`,
     * which would load a second copy of every shared dependency into this process). It is appended
     * after Composer's, so a class this host does install always loads from the host's vendor. The
     * scan itself is popcorn's {@see DeclaredRegistryRoots}, which applies the bake's population
     * rules (source pre-filter, abstract/interface/non-Registry excluded, `''` skipped).
     *
     * A declaration this process cannot load would silently shorten the catalog, so the scan is
     * checked against a second, differently-shaped probe: every source file under the package's
     * PSR-4 directories whose text opens a line with `#[IsRegistry(` must be accounted for — as a
     * declared root, a non-conforming class, or an abstract class. Anything else is an error, and
     * the command writes nothing.
     *
     * @param  array<string, string>  $packages  package name => absolute path, none of them installed here
     * @return array{entries: array<string, array{class: class-string, file: string}>, errors: list<string>}
     */
    public function ofPackages(array $packages): array
    {
        $entries = [];
        $errors = [];

        $scanned = array_map(fn (string $path): string => realpath($path) ?: $path, $this->scan->paths());

        foreach ($packages as $name => $base) {
            // Already in the census (`popcorn.bake.paths` reaches into it): nothing to add.
            foreach ($scanned as $path) {
                if ($path === $base || str_starts_with($path, rtrim($base, '/').'/')) {
                    continue 2;
                }
            }

            $psr4 = $this->psr4($base);

            // A docs-only directory (no composer.json, no source) declares nothing to census.
            if ($psr4 === [] && ! is_file($base.'/composer.json') && ! is_dir($base.'/src') && ! is_dir($base.'/app')) {
                continue;
            }

            if ($psr4 === []) {
                $errors[] = "{$name}: composer.json at {$base} declares no autoload.psr-4 directory, so its declarations cannot be censused";

                continue;
            }

            $this->autoload($psr4);
            $census = new DeclaredRegistryRoots(array_values(array_unique(array_merge(...array_values($psr4)))));

            foreach ($census->unloadable as $class => $message) {
                $errors[] = "{$name}: the declaration census could not load {$class} ({$message})";
            }

            $seen = array_fill_keys(array_map(fn (string $class): string => $this->fileOf($class), [...array_values($census->classes), ...$census->nonConforming]), true);

            foreach ($this->attributedFiles($psr4) as $file) {
                if (! isset($seen[$file]) && ! $this->isAbstractDeclaration($file)) {
                    $errors[] = "{$name}: {$file} declares #[IsRegistry] but its class did not load in this process, so its root is missing from the census";
                }
            }

            foreach ($census->classes as $root => $class) {
                $entries[$root] = ['class' => $class, 'file' => $this->fileOf($class)];
            }
        }

        ksort($entries);

        return ['entries' => $entries, 'errors' => $errors];
    }

    /** @return array<string, list<string>> namespace prefix => absolute directories, from the package's composer.json */
    private function psr4(string $base): array
    {
        $composer = json_decode((string) @file_get_contents($base.'/composer.json'), true);
        $map = [];

        foreach ((array) ($composer['autoload']['psr-4'] ?? []) as $prefix => $dirs) {
            foreach ((array) $dirs as $dir) {
                if (is_string($prefix) && is_string($dir) && ($real = realpath($base.'/'.$dir)) !== false && is_dir($real)) {
                    $map[$prefix][] = $real;
                }
            }
        }

        return $map;
    }

    /** @param  array<string, list<string>>  $psr4 */
    private function autoload(array $psr4): void
    {
        spl_autoload_register(function (string $class) use ($psr4): void {
            foreach ($psr4 as $prefix => $dirs) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }

                foreach ($dirs as $dir) {
                    $file = $dir.'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

                    if (is_file($file)) {
                        require_once $file;

                        return;
                    }
                }
            }
        });
    }

    /**
     * @param  array<string, list<string>>  $psr4
     * @return list<string> realpaths of files with a line opening `#[IsRegistry(`
     */
    private function attributedFiles(array $psr4): array
    {
        $files = [];

        foreach (array_unique(array_merge(...array_values($psr4))) as $dir) {
            foreach ((new Finder)->files()->name('*.php')->in($dir) as $file) {
                if (preg_match('/^#\[IsRegistry\(/m', (string) file_get_contents($file->getRealPath())) === 1) {
                    $files[] = (string) $file->getRealPath();
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function isAbstractDeclaration(string $file): bool
    {
        return preg_match('/^\s*(abstract\s+class|interface|trait)\s/m', (string) file_get_contents($file)) === 1;
    }

    /** @param  class-string  $class */
    private function fileOf(string $class): string
    {
        $file = (string) (new ReflectionClass($class))->getFileName();

        return realpath($file) ?: $file;
    }

    /**
     * Which known package DECLARES each root — by the declaring file's path, longest install-path
     * prefix winning (a package nested inside another's tree is the more specific owner). Shared by
     * the catalog and scaffold commands so ownership is computed one way, never guessed.
     *
     * @param  array<string, array{class: class-string, file: string}>  $entries  {@see entries()}
     * @param  array<string, string>  $packages  package name => absolute install path
     * @return array<string, ?string> root => owning package name, null when unowned
     */
    public function owners(array $entries, array $packages): array
    {
        uasort($packages, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $owners = [];

        foreach ($entries as $root => $declaration) {
            $owners[$root] = null;
            $file = realpath($declaration['file']) ?: $declaration['file'];

            foreach ($packages as $name => $base) {
                if (str_starts_with($file, rtrim($base, '/').'/')) {
                    $owners[$root] = $name;

                    break;
                }
            }
        }

        return $owners;
    }

    /**
     * The package that owns the cluster `$prefix` (registry-docs ticket 01, ruling 7), or null when
     * no owned declared root is in it — the root equal to the prefix counts as a member. Ownership: the package declaring the most roots under the
     * prefix; tiebreak, deterministic — first the package declaring the root equal to the prefix
     * itself, else the lexicographically first `vendor/name` among the tied.
     *
     * @param  array<string, ?string>  $owners  {@see owners()}
     */
    public function clusterOwner(string $prefix, array $owners): ?string
    {
        if ($prefix === '') {
            return null;
        }

        $tally = [];

        foreach ($owners as $root => $owner) {
            if ($owner !== null && ((string) $root === $prefix || str_starts_with((string) $root, $prefix.'.'))) {
                $tally[$owner] = ($tally[$owner] ?? 0) + 1;
            }
        }

        if ($tally === []) {
            return null;
        }

        $tied = array_keys($tally, max($tally), true);

        if (count($tied) === 1) {
            return $tied[0];
        }

        if (($owners[$prefix] ?? null) !== null && in_array($owners[$prefix], $tied, true)) {
            return $owners[$prefix];
        }

        sort($tied);

        return $tied[0];
    }
}
