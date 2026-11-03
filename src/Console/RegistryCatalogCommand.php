<?php

namespace Splicewire\Beam\Docs\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Docs\Registries\CatalogRow;
use Splicewire\Beam\Docs\Registries\FamilyPackages;
use Splicewire\Beam\Docs\Registries\PackageDocsManifest;
use Splicewire\Beam\Docs\Registries\RegistryCensus;
use Splicewire\Beam\Docs\Registries\RegistryDocsCatalog;

/**
 * Regenerate the committed product-documentation catalog (registry-docs ticket 01, amended
 * ruling 2): every family registry root in the `#[IsRegistry]` declaration census, each with its
 * doc pointer — the owner package's conventional `docs/registries/<root>.mdx`, a manifest override,
 * an external URL, an exemption with its reason, or `null` while uncovered. **This command is the
 * only writer**; the catalog's verification compare is ticket 04's and never writes
 * (`docs/conventions/regenerating-committed-artifacts.md`).
 *
 * Validation is EXACT ROOT MEMBERSHIP against the census — never `ExistsInRegistry`, which answers
 * entry-in-registry (ruling 3) — and the census is where DEAD ROOTS are detected: a manifest entry
 * or page file naming a root nothing declares fails the run, with nothing written. A root declared
 * by one package but named in another's manifest fails the same way (pages live in the declaring
 * package, ruling 2). The empty root `''` is structurally skipped at every tier: the census never
 * emits it and a manifest key of `''` never becomes a row.
 *
 * Scope is what this process can SEE: the census walks `popcorn.bake.paths` (or this install), and
 * manifests come from installed family packages plus `beam.docs.registries.packages` — the seam a
 * spine host uses to regenerate over packages it does not install, because one host's bake is
 * never the estate census (R4). Roots declared outside every known package keep `owner: null`.
 */
class RegistryCatalogCommand extends Command
{
    protected $signature = 'splicewire:beam:docs:registries:catalog
        {--write-to= : Write the catalog here instead of the configured committed path (beam.docs.registries.catalog)}';

    protected $description = 'Regenerate the committed registry documentation catalog from the declaration census and the per-package docs manifests.';

    public function handle(RegistryCensus $census, FamilyPackages $packages, RegistryDocsCatalog $catalog): int
    {
        $entries = $census->entries();

        if ($entries === []) {
            $this->error('The declaration census found no registry roots — nothing to catalog (check popcorn.bake.paths / the installed packages).');

            return self::FAILURE;
        }

        $known = $packages->forCatalog();
        $owners = $this->owners($entries, $known);
        $errors = [];
        $manifests = [];
        $pages = [];

        foreach ($known as $name => $base) {
            $dir = $base.'/docs/registries';

            if (! is_dir($dir)) {
                continue;
            }

            $manifest = PackageDocsManifest::read($dir);
            array_push($errors, ...$manifest->errors);
            $manifests[$name] = $manifest;

            foreach ($manifest->roots() as $root) {
                if (! isset($entries[$root])) {
                    $errors[] = "{$name}: manifest names `{$root}`, which no `#[IsRegistry]` declaration in the census declares (dead root)";
                } elseif (($owners[$root] ?? null) !== $name) {
                    $errors[] = "{$name}: manifest names `{$root}`, which is declared by ".($owners[$root] ?? 'no known package').' — a doc pointer lives in the declaring package (ruling 2)';
                }
            }

            foreach ($manifest->overrides as $root => $path) {
                if (! is_file($base.'/'.ltrim($path, '/'))) {
                    $errors[] = "{$name}: overrides.{$root} points at `{$path}`, which does not exist in the package";
                }
            }

            foreach (glob($dir.'/*.mdx') ?: [] as $file) {
                $root = basename($file, '.mdx');

                if (! isset($entries[$root])) {
                    $errors[] = "{$name}: docs/registries/".basename($file).' names no declared root (dead root) — the filename is the dotted root, verbatim';
                } elseif (($owners[$root] ?? null) !== $name) {
                    $errors[] = "{$name}: docs/registries/".basename($file).' documents a root declared by '.($owners[$root] ?? 'no known package').' — pages live in the declaring package (ruling 2)';
                } else {
                    $pages[$root] = 'docs/registries/'.basename($file);
                }
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            $this->error(count($errors).' error(s); the catalog was NOT written.');

            return self::FAILURE;
        }

        $publicBase = rtrim((string) config('beam.docs.segment', '/docs'), '/').'/registries/';
        $rows = [];
        $counts = ['page' => 0, 'external' => 0, 'exempt' => 0, 'uncovered' => 0];

        foreach ($entries as $root => $declaration) {
            $owner = $owners[$root] ?? null;
            $manifest = $owner !== null ? ($manifests[$owner] ?? null) : null;

            $row = match (true) {
                $manifest !== null && isset($manifest->overrides[$root]) => new CatalogRow($root, $owner, CatalogRow::KIND_PAGE, path: $manifest->overrides[$root]),
                isset($pages[$root]) => new CatalogRow($root, $owner, CatalogRow::KIND_PAGE, path: $pages[$root], url: $publicBase.$root),
                $manifest !== null && isset($manifest->external[$root]) => new CatalogRow($root, $owner, CatalogRow::KIND_EXTERNAL, url: $manifest->external[$root]),
                $manifest !== null && isset($manifest->exempt[$root]) => new CatalogRow($root, $owner, CatalogRow::KIND_EXEMPT, reason: $manifest->exempt[$root]['reason'], pointer: $manifest->exempt[$root]['pointer']),
                default => new CatalogRow($root, $owner, null),
            };

            $counts[$row->kind ?? 'uncovered']++;
            $rows[$root] = $row->toArray();
        }

        $path = $this->option('write-to') ?: $catalog->path();
        $path = str_starts_with((string) $path, '/') ? (string) $path : base_path((string) $path);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode([
            'version' => 1,
            'generated_by' => $this->getName(),
            'roots' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $catalog->fresh();

        $this->info(sprintf(
            'Catalog written to %s: %d roots (%d pages, %d external, %d exempt, %d uncovered).',
            $path, count($rows), $counts['page'], $counts['external'], $counts['exempt'], $counts['uncovered'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{class: class-string, file: string}>  $entries
     * @param  array<string, string>  $packages
     * @return array<string, ?string> root => owning package name (longest install-path prefix wins), null when unowned
     */
    private function owners(array $entries, array $packages): array
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
}
