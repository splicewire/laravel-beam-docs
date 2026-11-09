<?php

namespace Splicewire\Beam\Docs\PhpApi;

use JsonException;
use Splicewire\Beam\Docs\Console\GeneratePhpApiCommand;

/**
 * The generated PHP API artifact as a request reads it (php-api-reference slice 2). Cheap by construction: it reads the
 * small manifest, then only the index or symbol file asked for. It never generates, never parses or walks package
 * source and never loads package code; whether the SOURCE has moved on is the doctor's question (`docs.php-api`).
 *
 * The artifact is AVAILABLE only when every invariant holds, checked before any index or symbol is opened:
 *
 * - `beam.docs.php_api.packages` is non-empty (empty is the explicit off state, whatever is on disk);
 * - the artifact root is a real directory (not a symlink) carrying {@see ArtifactWriter::MARKER};
 * - the manifest decodes, is this schema, was generated for the CONFIGURED audience and from the configured package list
 *   and reference patterns (`inputDigest`), and names each index at exactly the path the writer gives that package;
 * - at least one symbol is published.
 *
 * Anything else is off, never an error: a mismatched artifact must not serve the wrong audience's content.
 *
 * Paths are never formed from input. A symbol is found by FQN in the package indexes, and the index row's path must be
 * exactly {@see ArtifactWriter::symbolPath()} of that row's FQN. Every file opened must resolve (symlinks followed) to a
 * regular file inside the artifact root; a link pointing out is refused.
 */
final class PhpApiArtifact
{
    /** A bound on any single file read, far above a real symbol document. */
    public const MAX_BYTES = 8 * 1024 * 1024;

    /** The validated manifest, or null when the artifact is off. */
    public function manifest(): ?array
    {
        $configured = (array) config('beam.docs.php_api.packages', []);
        if ($configured === []) {
            return null;
        }

        $root = $this->root();
        if ($root === null) {
            return null;
        }

        $manifest = $this->read($root, 'manifest.json');
        $audience = GeneratePhpApiCommand::audience();
        $patterns = array_values((array) config('beam.docs.php_api.references', []));

        if ($manifest === null
            || ($manifest['schema'] ?? null) !== PhpApiGenerator::SCHEMA
            || ($manifest['audience'] ?? null) !== $audience
            || ($manifest['inputDigest'] ?? null) !== ArtifactWriter::inputDigest($configured, $audience, $patterns)
            || ! is_array($manifest['packages'] ?? null) || ! array_is_list($manifest['packages'])
            || ! is_int($manifest['totals']['published'] ?? null) || $manifest['totals']['published'] < 1) {
            return null;
        }

        foreach ($manifest['packages'] as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)
                || (($package['index'] ?? null) !== null && $package['index'] !== ArtifactWriter::indexPath($package['name']))) {
                return null;
            }
        }

        return $manifest;
    }

    public function available(): bool
    {
        return $this->manifest() !== null;
    }

    /**
     * Every generated package with its published symbol rows, in manifest order; null when off or when an index the
     * manifest names cannot be read safely.
     *
     * @return ?array{audience: string, packages: list<array{name: string, version: ?string, published: int, symbols: list<array{fqn: string, kind: string, summary: ?string}>}>}
     */
    public function index(): ?array
    {
        $indexes = $this->indexes();
        if ($indexes === null) {
            return null;
        }

        $packages = [];
        foreach ($indexes['packages'] as $package) {
            $packages[] = [
                'name' => $package['name'],
                'version' => is_string($package['version'] ?? null) ? $package['version'] : null,
                'published' => count($package['rows']),
                'symbols' => array_map(fn (array $row): array => [
                    'fqn' => $row['fqn'],
                    'kind' => $row['kind'],
                    'summary' => is_string($row['summary'] ?? null) ? $row['summary'] : null,
                ], $package['rows']),
            ];
        }

        return ['audience' => $indexes['audience'], 'packages' => $packages];
    }

    /** One published symbol's document, found by FQN in the indexes; null when off, unknown or unsafe. */
    public function symbol(string $fqn): ?array
    {
        $indexes = $this->indexes();
        if ($indexes === null) {
            return null;
        }

        foreach ($indexes['packages'] as $package) {
            foreach ($package['rows'] as $row) {
                if ($row['fqn'] !== $fqn) {
                    continue;
                }
                // The index holds the path; it is opened only if it is exactly the writer's path for that row.
                if ($row['path'] !== ArtifactWriter::symbolPath($row['fqn'])) {
                    return null;
                }
                $symbol = $this->read($indexes['root'], $row['path']);

                return $symbol !== null && ($symbol['schema'] ?? null) === PhpApiGenerator::SCHEMA
                    && ($symbol['fqn'] ?? null) === $fqn && ($symbol['package'] ?? null) === $package['name']
                    ? $symbol : null;
            }
        }

        return null;
    }

    /** @return ?array{root: string, audience: string, packages: list<array{name: string, version: mixed, rows: list<array<string, mixed>>}>} */
    private function indexes(): ?array
    {
        $manifest = $this->manifest();
        $root = $manifest === null ? null : $this->root();
        if ($root === null) {
            return null;
        }

        $packages = [];
        foreach ($manifest['packages'] as $package) {
            if (($package['status'] ?? null) !== 'ok' || $package['index'] === null) {
                continue;
            }
            $index = $this->read($root, $package['index']);
            if ($index === null || ($index['schema'] ?? null) !== PhpApiGenerator::SCHEMA
                || ($index['package'] ?? null) !== $package['name'] || ! is_array($index['symbols'] ?? null)) {
                return null;
            }
            $rows = [];
            foreach ($index['symbols'] as $row) {
                if (! is_array($row) || ! is_string($row['fqn'] ?? null) || ! is_string($row['kind'] ?? null) || ! is_string($row['path'] ?? null)) {
                    return null;
                }
                $rows[] = $row;
            }
            $packages[] = ['name' => $package['name'], 'version' => $package['version'] ?? null, 'rows' => $rows];
        }

        return ['root' => $root, 'audience' => $manifest['audience'], 'packages' => $packages];
    }

    /** The artifact root's real path: a real directory, not a symlink, carrying the writer's marker. */
    private function root(): ?string
    {
        $path = rtrim(GeneratePhpApiCommand::artifactPath(), '/');
        if ($path === '' || is_link($path) || ! is_dir($path)) {
            return null;
        }
        $marker = $path.'/'.ArtifactWriter::MARKER;
        if (is_link($marker) || ! is_file($marker)) {
            return null;
        }

        return realpath($path) ?: null;
    }

    /** A JSON object at a relative path the artifact itself named, resolved inside the root; null otherwise. */
    private function read(string $root, string $relative): ?array
    {
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '\\') || str_contains($relative, "\0")
            || in_array('..', explode('/', $relative), true)) {
            return null;
        }

        $real = realpath($root.'/'.$relative);
        if ($real === false || ! str_starts_with($real, $root.'/') || ! is_file($real)) {
            return null;
        }
        $size = filesize($real);
        if ($size === false || $size > self::MAX_BYTES) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($real), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }
}
