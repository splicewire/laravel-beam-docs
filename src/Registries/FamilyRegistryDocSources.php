<?php

namespace Splicewire\Beam\Docs\Registries;

use Splicewire\Beam\Docs\Seed\DocsSourcesSeeder;

/**
 * The GENERIC `beam.docs.sources` contributor (registry-docs ticket 01, ruling 2): one row per
 * INSTALLED family package that ships registry pages under `docs/registries/`, so a declaring
 * package's pages reach the site while the package itself carries zero beam knowledge — rushing
 * packages never learn the transport. The row is the DOC-4 shape (path + product + `package:`
 * stamp, tower's docs-bundle precedent); {@see DocsSourcesSeeder}
 * materializes it in the seed chain, and the `docs.unregistered` audit reads it like any other.
 *
 * Runs on `booted()` — after every provider — so a package guarding its OWN docs-bundle row by
 * `package:` stamp at its boot (tower's `registerDocsBundle`) never meets this generic row first
 * and skips its own. Idempotence here is by PATH, not package, for the same reason: one package
 * may legitimately carry both its bundle row and its registry-pages row.
 *
 * Non-installed packages' roots are never mounted — they are covered by their catalog rows'
 * pointers (ruling 2), so this reads {@see FamilyPackages::installed()}, never the catalog seam.
 */
class FamilyRegistryDocSources
{
    public function __construct(private FamilyPackages $packages) {}

    public function contribute(): void
    {
        // Rows only where the seed chain can materialize them: with docs seeding off, a contributed
        // row would turn `splicewire:beam:seed` into a guaranteed throw (no `/docs/registries`
        // parent) and fail the docs.unregistered audit against pages the host chose not to serve.
        if (! config('beam.docs.enabled', true) || ! config('beam.docs.seed', true)) {
            return;
        }

        $sources = array_values((array) config('beam.docs.sources', []));
        $known = array_filter(array_map(fn ($source) => $source['path'] ?? null, $sources), 'is_string');
        $under = rtrim((string) config('beam.docs.segment', '/docs'), '/').'/registries';

        foreach ($this->packages->installed() as $name => $base) {
            $dir = $base.'/docs/registries';

            // A manifest-only package (all overrides/external/exempt) has nothing to mount.
            if (! is_dir($dir) || (glob($dir.'/*.mdx') ?: []) === [] || in_array($dir, $known, true)) {
                continue;
            }

            $sources[] = [
                'path' => $dir,
                'under' => $under,
                'type' => 'page',
                'product' => (string) config('beam.docs.registries.product', 'splicewire'),
                'package' => $name,
                // The manifest is data, not a page; a spine host's committed catalog lives beside it.
                'ignore' => [PackageDocsManifest::FILENAME, 'catalog.json'],
            ];
            $known[] = $dir;
        }

        config(['beam.docs.sources' => $sources]);
    }
}
