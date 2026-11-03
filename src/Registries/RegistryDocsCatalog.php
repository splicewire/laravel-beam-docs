<?php

namespace Splicewire\Beam\Docs\Registries;

use Splicewire\Beam\Docs\Console\RegistryCatalogCommand;

/**
 * The reader of the committed product-documentation catalog (registry-docs ticket 01, amended
 * ruling 2): every family registry root with its doc pointer, INDEPENDENT of this host's installed
 * runtime roots — product documentation coverage and host runtime composition are two different
 * quantities. Regeneration is {@see RegistryCatalogCommand} — the
 * command writes, this class and everything downstream of it only read
 * (`docs/conventions/regenerating-committed-artifacts.md`).
 *
 * The file lives where `beam.docs.registries.catalog` points (relative paths resolve against
 * `base_path()`). A host without one — every host but the spine, until ticket 08 rolls out — reads
 * an empty catalog, and consumers degrade to their entries-table answer.
 */
class RegistryDocsCatalog
{
    /** @var array<string, CatalogRow>|null */
    private ?array $rows = null;

    /** @var array<string, array{owner: ?string, path: ?string, url: ?string, title: ?string}>|null */
    private ?array $clusters = null;

    public function path(): string
    {
        $configured = config('beam.docs.registries.catalog');
        $path = is_string($configured) && $configured !== '' ? $configured : 'docs/registries/catalog.json';

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    public function row(string $root): ?CatalogRow
    {
        return $root === '' ? null : ($this->rows()[$root] ?? null);
    }

    /** @return array<string, CatalogRow> root => row, as committed */
    public function rows(): array
    {
        return $this->rows ??= $this->load();
    }

    /**
     * The cluster pages the catalog records (ruling 7), `prefix => {owner, path, url, title}`, as
     * committed. Empty for a catalog written before any cluster page existed.
     *
     * @return array<string, array{owner: ?string, path: ?string, url: ?string, title: ?string}>
     */
    public function clusters(): array
    {
        if ($this->clusters !== null) {
            return $this->clusters;
        }

        $decoded = is_file($path = $this->path()) ? json_decode((string) file_get_contents($path), true) : null;
        $clusters = [];

        foreach (is_array($decoded['clusters'] ?? null) ? $decoded['clusters'] : [] as $prefix => $cluster) {
            if (is_string($prefix) && $prefix !== '' && is_array($cluster)) {
                $string = fn (string $key): ?string => is_string($cluster[$key] ?? null) && $cluster[$key] !== '' ? $cluster[$key] : null;
                $clusters[$prefix] = ['owner' => $string('owner'), 'path' => $string('path'), 'url' => $string('url'), 'title' => $string('title')];
            }
        }

        return $this->clusters = $clusters;
    }

    /** Drop the memoized rows — the command calls this after writing, so one process sees its own write. */
    public function fresh(): static
    {
        $this->rows = null;
        $this->clusters = null;

        return $this;
    }

    /** @return array<string, CatalogRow> */
    private function load(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! is_array($decoded['roots'] ?? null)) {
            return [];
        }

        $rows = [];

        foreach ($decoded['roots'] as $root => $row) {
            // The empty root is structurally skipped at every tier (ruling 3) — a committed catalog
            // carrying it is malformed, and the row is dropped rather than resolved.
            if (! is_string($root) || $root === '' || ! is_array($row)) {
                continue;
            }

            $rows[$root] = CatalogRow::fromArray($root, $row);
        }

        return $rows;
    }
}
