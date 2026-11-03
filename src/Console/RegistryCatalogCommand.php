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

        $errors = [];
        $installed = $packages->installed();

        // The estate seam: configured packages this host does not install join the census here, so
        // the catalog covers what the product documents rather than what this host runs (R4). A
        // configured package whose path is missing fails the run rather than shrinking it.
        $extras = [];

        foreach ($packages->configured() as $name => $path) {
            if ($path === null) {
                $errors[] = "{$name}: beam.docs.registries.packages points at a path that is not a directory on this machine";
            } elseif (! isset($installed[$name])) {
                $extras[$name] = $path;
            }
        }

        if ($extras !== []) {
            ['entries' => $extraEntries, 'errors' => $extraErrors] = $census->ofPackages($extras);
            array_push($errors, ...$extraErrors);

            foreach ($extraEntries as $root => $declaration) {
                if (isset($entries[$root]) && $entries[$root]['class'] !== $declaration['class']) {
                    $errors[] = "`{$root}` is declared twice in the census: {$entries[$root]['class']} and {$declaration['class']}";
                }

                $entries[$root] ??= $declaration;
            }

            ksort($entries);
        }

        $known = $packages->forCatalog();
        $owners = $census->owners($entries, $known);
        $manifests = [];
        $pages = [];
        $clusters = [];

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

                if (! isset($entries[$root]) && ($clusterOwner = $census->clusterOwner($root, $owners)) !== null) {
                    // A cluster page (ruling 7): the filename is a prefix shared by declared roots,
                    // and it lives in the package that owns the cluster — never a dead root.
                    $clusterOwner === $name
                        ? $clusters[$root] = ['owner' => $name, 'path' => 'docs/registries/'.basename($file), ...(($title = self::title($file)) === null ? [] : ['title' => $title])]
                        : $errors[] = "{$name}: docs/registries/".basename($file)." is a cluster page, but the `{$root}` cluster is owned by {$clusterOwner} (most roots, then the root equal to the prefix, then the first vendor/name — ruling 7)";
                } elseif (! isset($entries[$root])) {
                    $errors[] = "{$name}: docs/registries/".basename($file).' names no declared root or cluster prefix (dead root) — the filename is the dotted root, verbatim';
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
                $manifest !== null && isset($manifest->exempt[$root]) => new CatalogRow($root, $owner, CatalogRow::KIND_EXEMPT, reason: $manifest->exempt[$root]['reason'], pointer: $manifest->exempt[$root]['pointer'], exemptionClass: $manifest->exempt[$root]['class']),
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

        ksort($clusters);

        foreach ($clusters as $prefix => $cluster) {
            // url before title, so the committed key order is owner, path, url, title.
            $clusters[$prefix] = ['owner' => $cluster['owner'], 'path' => $cluster['path'], 'url' => $publicBase.$prefix, ...(isset($cluster['title']) ? ['title' => $cluster['title']] : [])];
        }

        file_put_contents($path, json_encode([
            'version' => 1,
            'generated_by' => $this->getName(),
            'roots' => $rows,
            // Only where a cluster page exists, so a catalog without one is byte-identical to before.
            ...($clusters === [] ? [] : ['clusters' => $clusters]),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $catalog->fresh();

        $this->info(sprintf(
            'Catalog written to %s: %d roots (%d pages, %d external, %d exempt, %d uncovered), %d cluster page(s).',
            $path, count($rows), $counts['page'], $counts['external'], $counts['exempt'], $counts['uncovered'], count($clusters),
        ));

        return self::SUCCESS;
    }

    /**
     * A cluster page's frontmatter `title`, so the nav can name a cluster this host does not serve
     * (ruling 7: the catalog, not the host bake, is the nav's input). Null where there is none.
     */
    private static function title(string $file): ?string
    {
        if (preg_match('/\A---\R(.*?)\R---/s', (string) file_get_contents($file), $front) !== 1) {
            return null;
        }

        return preg_match('/^title:\s*(.+?)\s*$/m', $front[1], $title) === 1 ? trim($title[1], "\"'") : null;
    }
}
