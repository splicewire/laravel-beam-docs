<?php

namespace Splicewire\Beam\Docs\PhpApi;

use Illuminate\Validation\ValidationException;
use JsonException;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use Splicewire\Beam\Docs\Console\GeneratePhpApiCommand;
use Splicewire\Beam\Docs\Data\PhpApiIndexData;
use Splicewire\Beam\Docs\Data\PhpApiSymbolData;

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
 *   and reference patterns (`inputDigest`), and every package row is well formed: a string `name` and `status`, a
 *   string-or-null `version`, an integer `published`, and an `index` that is exactly the writer's path for an `ok` row
 *   and null otherwise;
 * - at least one symbol is published.
 *
 * Anything else is off, never an error: a mismatched or malformed artifact must not serve the wrong content, or a 500.
 *
 * Fail-closed reads (slice 2 amend, review 47b37664). Only what a request needs is judged: the manifest always; the
 * package indexes for the index op and for a symbol lookup; and the ONE selected symbol. Each index row must be well
 * formed (string `fqn`, `kind`, `path`, and a string-or-null `summary`). The response Data is hydrated HERE, through
 * laravel-data's own validation ({@see PhpApiIndexData::validateAndCreate()}), so a structurally incomplete index or
 * symbol (missing or mistyped required fields, at any nesting depth) is null at this boundary, which the ops answer as
 * the uniform 404. Only the expected invalid-data failures (validation, Data creation) are caught; anything else is a
 * programmer error and still raises.
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
            if (! $this->wellFormedPackage($package)) {
                return null;
            }
        }

        return $manifest;
    }

    public function available(): bool
    {
        return $this->manifest() !== null;
    }

    /** Every generated package with its published symbol rows, typed; null when off or any needed index is malformed. */
    public function index(): ?PhpApiIndexData
    {
        $indexes = $this->indexes();
        if ($indexes === null) {
            return null;
        }

        $packages = [];
        foreach ($indexes['packages'] as $package) {
            $packages[] = [
                'name' => $package['name'],
                'version' => $package['version'],
                'published' => count($package['rows']),
                'symbols' => array_map(fn (array $row): array => ['fqn' => $row['fqn'], 'kind' => $row['kind'], 'summary' => $row['summary']], $package['rows']),
            ];
        }

        return $this->hydrate(PhpApiIndexData::class, ['audience' => $indexes['audience'], 'packages' => $packages]);
    }

    /** One published symbol's document, found by FQN in the indexes, typed; null when off, unknown, unsafe or malformed. */
    public function symbol(string $fqn): ?PhpApiSymbolData
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
                if ($symbol === null || ($symbol['schema'] ?? null) !== PhpApiGenerator::SCHEMA
                    || ($symbol['fqn'] ?? null) !== $fqn || ($symbol['package'] ?? null) !== $package['name']) {
                    return null;
                }
                unset($symbol['schema']);

                return $this->hydrate(PhpApiSymbolData::class, $symbol);
            }
        }

        return null;
    }

    /**
     * The typed Data, or null when the payload does not satisfy it (the expected failure of a malformed artifact).
     *
     * @template T of \Spatie\LaravelData\Data
     *
     * @param  class-string<T>  $class
     * @return ?T
     */
    private function hydrate(string $class, array $payload): ?object
    {
        try {
            return $class::validateAndCreate($payload);
        } catch (ValidationException|CannotCreateData) {
            return null;
        }
    }

    private function wellFormedPackage(mixed $package): bool
    {
        if (! is_array($package) || ! is_string($package['name'] ?? null) || ! is_string($package['status'] ?? null)
            || ! array_key_exists('index', $package) || ! is_int($package['published'] ?? null)
            || ! (($package['version'] ?? null) === null || is_string($package['version']))) {
            return false;
        }

        return $package['status'] === 'ok'
            ? $package['index'] === ArtifactWriter::indexPath($package['name'])
            : $package['index'] === null;
    }

    private function wellFormedRow(mixed $row): bool
    {
        return is_array($row) && is_string($row['fqn'] ?? null) && is_string($row['kind'] ?? null) && is_string($row['path'] ?? null)
            && array_key_exists('summary', $row) && ($row['summary'] === null || is_string($row['summary']));
    }

    /** @return ?array{root: string, audience: string, packages: list<array{name: string, version: ?string, rows: list<array<string, mixed>>}>} */
    private function indexes(): ?array
    {
        $manifest = $this->manifest();
        $root = $manifest === null ? null : $this->root();
        if ($root === null) {
            return null;
        }

        $packages = [];
        foreach ($manifest['packages'] as $package) {
            if ($package['status'] !== 'ok') {
                continue;
            }
            $index = $this->read($root, $package['index']);
            if ($index === null || ($index['schema'] ?? null) !== PhpApiGenerator::SCHEMA
                || ($index['package'] ?? null) !== $package['name']
                || ! is_array($index['symbols'] ?? null) || ! array_is_list($index['symbols'])) {
                return null;
            }
            foreach ($index['symbols'] as $row) {
                if (! $this->wellFormedRow($row)) {
                    return null;
                }
            }
            $packages[] = ['name' => $package['name'], 'version' => $package['version'] ?? null, 'rows' => $index['symbols']];
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
