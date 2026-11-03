<?php

namespace Splicewire\Beam\Docs\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Rushing\Popcorn\Registries\Docs\PackageRegistryDocs;
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
 * Coverage from (1) is accepted from any installed package, not only the declaring one. Pinning a
 * pointer to its declaring package is the catalog command's and the package tier's rule, and both
 * enforce it. This advisory audit asks only whether documentation exists for what this host runs.
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

        foreach ($roots as $root) {
            $kind = $this->coverage($root, $docs);
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
    private function coverage(string $root, array $docs): ?string
    {
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
}
