<?php

namespace Splicewire\Beam\Docs\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Popcorn\Registries\Docs\PackageRegistryDocs;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Docs\Registries\FamilyPackages;
use Splicewire\Beam\Docs\Registries\RegistryDocsCatalog;
use Throwable;

/**
 * `docs.registries.coverage`: the host tier of the two-tier registry documentation audit
 * (registry-docs ticket 01, amended ruling 5; built by ticket 04). It is registered ADVISORY, so a
 * Fail reports and does not block the doctor's exit code.
 *
 * **Population: every root in THIS host's runtime registry index, minus the empty root `''`** (the
 * index's own, structurally skipped at every tier by ruling 3). That is a host population, never a fleet
 * count, and the finding says so. The flagship's 95 baked roots and www's 56 are two hosts' numbers.
 * Product documentation coverage over every declared root is the spine catalog's quantity (ruling 2).
 *
 * A root is covered when either of these holds:
 *
 *  1. an installed family package, or the host app itself, satisfies it from its own
 *     `docs/registries/`: a conventional page, an override whose file exists, an external URL, or
 *     an exemption with its class and reason, plus a pointer where the class needs one. That is
 *     {@see PackageRegistryDocs}, the same reader the package-tier check uses, so the two tiers
 *     cannot disagree about which entries are valid;
 *  2. otherwise, a committed catalog row on this host carries a doc pointer. That row covers
 *     packages this host does not install, and it was validated when the catalog command wrote it.
 *
 * **Root membership only.** The population is the index's root keys and the question is "does a
 * doc pointer exist for this root". `ExistsInRegistry` is never consulted: it answers whether an
 * entry exists at a key, a different question, and the empty root is not even a legal key for it
 * (ruling 3).
 *
 * Coverage from (1) is read from the DECLARING package when this host can tell which installed
 * package that is (the file of the class carrying `#[IsRegistry]`, found from the root's owner up its
 * parents, matched by longest install-path prefix). Only a root whose
 * declarer is not installed here falls back to any installed package. The reason is measured, not
 * stylistic (registry-docs ticket 08): a cluster page is a file named for a prefix, so at the
 * flagship `splicewire/laravel-composition-engine-music`'s `music.mdx` cluster page read as a page
 * for the app's own `music` root, and the app-local root counted as covered by a page about other
 * registries. Pinning a pointer to its declaring package is also the catalog command's and the
 * package tier's rule.
 */
class RegistryDocsCoverageAudit implements DoctorAudit
{
    public const CHECK = 'docs.registries.coverage';

    public function __construct(
        private RegistryIndex $index,
        private RegistryDocsCatalog $catalog,
        private FamilyPackages $packages,
    ) {}

    public function run(): array
    {
        try {
            $roots = array_map('strval', $this->index->unfiltered()->keys());
        } catch (Throwable $e) {
            return [Finding::inconclusive(self::CHECK, 'This host\'s registry index could not be read ('.$e->getMessage().'), so its roots could not be checked for documentation.')];
        }

        $roots = array_values(array_filter(array_unique($roots), fn (string $root): bool => $root !== ''));
        sort($roots);

        if ($roots === []) {
            return [Finding::inconclusive(self::CHECK, 'This host\'s registry index describes no root besides the empty root, so there is nothing to check.')];
        }

        $docs = [];
        $problems = [];

        foreach ($this->packages->installed() as $name => $path) {
            $package = new PackageRegistryDocs($path);

            if ($package->isActive()) {
                $docs[$name] = $package;
                foreach ($package->problems() as $problem) {
                    $problems[] = "{$name}: {$problem}";
                }
            }
        }

        $counts = [];
        $uncovered = [];

        $installed = $this->packages->installed();

        foreach ($roots as $root) {
            $kind = $this->coverage($root, $docs, $this->declarer($root, $installed));
            $kind === null ? $uncovered[] = $root : $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }

        ksort($counts);
        $breakdown = $counts === [] ? 'none' : implode(' · ', array_map(fn (string $kind, int $n): string => "{$n} {$kind}", array_keys($counts), $counts));
        $scope = sprintf(
            'Scope: this host\'s runtime registry index, %d root(s) (empty root excluded), a host population and not a fleet count; %d package(s) here ship docs/registries/.',
            count($roots), count($docs),
        );
        $tally = sprintf('%d covered (%s) · %d uncovered.', count($roots) - count($uncovered), $breakdown, count($uncovered));
        $notes = $problems === [] ? '' : ' Manifest problems, which leave their roots uncovered: '.implode('; ', $problems).'.';

        if ($uncovered !== []) {
            return [Finding::fail(self::CHECK, "{$scope} {$tally} Roots with no page, manifest pointer or exemption: ".implode(', ', $uncovered).".{$notes}")];
        }

        return [Finding::pass(self::CHECK, "{$scope} {$tally} Every root this host runs has catalog coverage.{$notes}")];
    }

    /**
     * How `$root` is covered here: a package kind, `catalog:<kind>`, or null where it is not.
     *
     * @param  array<string, PackageRegistryDocs>  $docs
     */
    private function coverage(string $root, array $docs, ?string $declarer): ?string
    {
        if ($declarer !== null) {
            $kind = isset($docs[$declarer]) ? $docs[$declarer]->satisfaction($root) : null;

            if ($kind !== null) {
                return $kind;
            }

            $docs = [];
        }

        foreach ($docs as $package) {
            if (($kind = $package->satisfaction($root)) !== null) {
                return $kind;
            }
        }

        try {
            $row = $this->catalog->row($root);
        } catch (Throwable) {
            $row = null;
        }

        return $row?->kind !== null ? 'catalog '.$row->kind : null;
    }

    /**
     * The installed package (or the host app) whose source declares `$root`, by the owner class's
     * file and the longest install-path prefix; null where this host cannot tell.
     *
     * @param  array<string, string>  $installed
     */
    private function declarer(string $root, array $installed): ?string
    {
        try {
            $owner = $this->index->unfiltered()->owner($root);
            $class = $owner === null ? false : new \ReflectionClass($owner);

            // The DECLARING class carries the attribute. A host that rebinds a registry with its own
            // subclass (the flagship's lazy workflow-bindings registry) owns the instance, not the
            // declaration, so walk up to the class that declares the root.
            while ($class !== false && $class->getAttributes(IsRegistry::class) === []) {
                $class = $class->getParentClass();
            }

            $file = $class === false ? false : $class->getFileName();
        } catch (Throwable) {
            return null;
        }

        if (! is_string($file)) {
            return null;
        }

        $file = realpath($file) ?: $file;
        uasort($installed, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        foreach ($installed as $name => $base) {
            if (str_starts_with($file, rtrim($base, '/').'/')) {
                return $name;
            }
        }

        return null;
    }
}
