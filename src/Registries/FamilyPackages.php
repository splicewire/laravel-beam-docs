<?php

namespace Splicewire\Beam\Docs\Registries;

use Composer\InstalledVersions;

/**
 * The family packages this process can see, as `vendor/name => absolute install path`.
 *
 * Enumeration comes from `\Composer\InstalledVersions` — the runtime API, correct in a host and a
 * testbench alike (the `installed.json` path-read is refused for the reason
 * `DeclaredRegistryScan::installedPackageRoots()` records) — filtered to the family vendors
 * (`popcorn.bake.vendors`), plus the ROOT package: a host owns its app-local roots, and ruling 4
 * exempts them in the host's own `docs/registries/manifest.json` at the same path.
 *
 * {@see forCatalog()} additionally merges `beam.docs.registries.packages` — explicit
 * `vendor/name => path` rows. That is the seam that lets the spine host regenerate the catalog over
 * family packages it does not itself install (one host's bake is never the estate census), and the
 * testbench fixture seam. The generic source contributor deliberately reads {@see installed()}
 * only: non-installed roots are covered by their catalog rows' pointers, never mounted (ruling 2).
 */
class FamilyPackages
{
    /** @return array<string, string> package name => absolute install path, installed here */
    public function installed(): array
    {
        if (! class_exists(InstalledVersions::class)) {
            return [];
        }

        $vendors = (array) config('popcorn.bake.vendors', ['rushing', 'schemastud', 'splicewire']);
        $found = [];

        foreach (InstalledVersions::getInstalledPackages() as $name) {
            if (! in_array(explode('/', $name)[0], $vendors, true)) {
                continue;
            }

            $base = InstalledVersions::getInstallPath($name);

            if ($base !== null && is_dir($base) && ($real = realpath($base)) !== false) {
                $found[$name] = $real;
            }
        }

        $root = InstalledVersions::getRootPackage();

        if (is_dir($root['install_path']) && ($real = realpath($root['install_path'])) !== false) {
            $found[$root['name']] ??= $real;
        }

        return $found;
    }

    /** @return array<string, string> installed packages plus the configured extras, extras winning on a name collision */
    public function forCatalog(): array
    {
        $extra = [];

        foreach ((array) config('beam.docs.registries.packages', []) as $name => $path) {
            if (is_string($name) && $name !== '' && is_string($path) && is_dir($path) && ($real = realpath($path)) !== false) {
                $extra[$name] = $real;
            }
        }

        return [...$this->installed(), ...$extra];
    }
}
