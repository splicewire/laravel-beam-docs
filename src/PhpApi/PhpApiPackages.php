<?php

namespace Splicewire\Beam\Docs\PhpApi;

use Composer\InstalledVersions;
use Splicewire\Beam\Docs\Registries\FamilyPackages;

/**
 * The packages `beam.docs.php_api.packages` lists, resolved to where their source is (php-api-reference slice 1).
 *
 * A row is `vendor/name` (a package this process has installed: its path and version come from
 * `\Composer\InstalledVersions`, as {@see FamilyPackages} reads them) or
 * `vendor/name => path` (a package this host does not install: the path is read as configured, and the version is
 * unknown). A row that resolves to no directory is kept, with `path` null, so the generator reports it instead of
 * writing a shorter artifact that reads like a complete one.
 */
final class PhpApiPackages
{
    /** Composer's own `vendor/name` grammar (composer/composer ValidatingArrayLoader). */
    public const NAME = '{^[a-z0-9](?:[_.-]?[a-z0-9]++)*+/[a-z0-9](?:(?:[_.]|-{1,2})?[a-z0-9]++)*+$}';

    /**
     * The configured rows, resolved and sorted by name.
     *
     * @return list<array{name: string, path: ?string, source: string, version: ?string, reference: ?string}>
     */
    public function resolve(array $configured): array
    {
        $rows = [];

        foreach ($configured as $key => $value) {
            $name = is_int($key) ? (is_string($value) ? $value : '') : $key;

            if ($name === '') {
                continue;
            }

            // Composer's package-name grammar; anything else (`a/../b`, spaces) never resolves and never forms a path.
            if (preg_match(self::NAME, $name) !== 1) {
                $rows[$name] = ['name' => $name, 'path' => null, 'source' => 'invalid', 'version' => null, 'reference' => null];
            } elseif (is_int($key)) {
                $rows[$name] = $this->installed($name);
            } else {
                $rows[$name] = $this->atPath($name, $value);
            }
        }

        ksort($rows, SORT_STRING);

        return array_values($rows);
    }

    /** @return array{name: string, path: ?string, source: string, version: ?string, reference: ?string} */
    private function installed(string $name): array
    {
        $path = null;
        $version = $reference = null;

        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($name)) {
            $base = InstalledVersions::getInstallPath($name);
            $path = $base !== null && is_dir($base) ? (realpath($base) ?: null) : null;
            $version = InstalledVersions::getPrettyVersion($name);
            $reference = InstalledVersions::getReference($name);
        }

        return ['name' => $name, 'path' => $path, 'source' => 'installed', 'version' => $version, 'reference' => $reference];
    }

    /** @return array{name: string, path: ?string, source: string, version: ?string, reference: ?string} */
    private function atPath(string $name, mixed $path): array
    {
        $real = is_string($path) && is_dir($path) ? (realpath($path) ?: null) : null;

        return ['name' => $name, 'path' => $real, 'source' => 'path', 'version' => null, 'reference' => null];
    }
}
