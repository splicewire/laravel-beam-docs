<?php

namespace Splicewire\Beam\Docs\Registries;

use ReflectionClass;
use Rushing\Popcorn\Laravel\Baking\DeclaredRegistryScan;

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
