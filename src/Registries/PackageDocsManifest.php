<?php

namespace Splicewire\Beam\Docs\Registries;

/**
 * One package's committed docs manifest — `docs/registries/manifest.json` (registry-docs ticket 01,
 * amended rulings 3–5). The ONE artifact that satisfies both audit tiers for a declared root that
 * has no conventional page, so it stays PLAIN JSON readable without Laravel: ticket 04's
 * package-tier check consumes this same file from framework-free `rushing/php-popcorn`.
 *
 * Shape (every section optional; a package with only conventional pages ships no manifest at all):
 *
 * ```json
 * {
 *     "overrides": { "<root>": "docs/guides/the-page.mdx" },
 *     "external":  { "<root>": "https://github.com/vendor/name#anchor" },
 *     "exempt":    { "<root>": { "reason": "rebind-only: hosts rebind, never register",
 *                                "pointer": "docs/adr/0001-why.md" } }
 * }
 * ```
 *
 * - `overrides` points a declared root at a NON-default page path, relative to the package root.
 * - `external` satisfies a root whose page is hosted elsewhere; the value must be an http(s) URL.
 * - `exempt` carries a mandatory non-empty `reason`; `pointer` names the doc that explains the
 *   internal mechanism and is mandatory for infrastructure and rebind-only roots (ruling 4 — an
 *   exemption without a pointer is a single-sourced absence claim).
 * - A root appears in at most ONE section, and only the declaring package's manifest may name it
 *   (exact root membership — enforced by the catalog command against the census, and per package
 *   by ticket 04's check).
 * - The empty root `''` never appears: it is structurally skipped at every tier (ruling 3), so a
 *   key of `''` is dropped here before any consumer sees it.
 */
class PackageDocsManifest
{
    public const FILENAME = 'manifest.json';

    /**
     * @param  array<string, string>  $overrides  root => package-relative page path
     * @param  array<string, string>  $external  root => http(s) URL
     * @param  array<string, array{reason: string, pointer: ?string}>  $exempt
     * @param  list<string>  $errors  shape violations, each naming the manifest and the key
     */
    public function __construct(
        public readonly array $overrides = [],
        public readonly array $external = [],
        public readonly array $exempt = [],
        public readonly array $errors = [],
    ) {}

    /** Read `<dir>/manifest.json`, where `$dir` is the package's `docs/registries` directory. */
    public static function read(string $dir): self
    {
        $file = rtrim($dir, '/').'/'.self::FILENAME;

        if (! is_file($file)) {
            return new self;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (! is_array($decoded)) {
            return new self(errors: ["{$file}: not a JSON object"]);
        }

        $errors = [];
        $sections = [];

        foreach (['overrides', 'external', 'exempt'] as $section) {
            $sections[$section] = [];
            $raw = $decoded[$section] ?? [];

            if (! is_array($raw)) {
                $errors[] = "{$file}: `{$section}` must be an object of roots";

                continue;
            }

            foreach ($raw as $root => $value) {
                if (! is_string($root) || $root === '') {
                    continue; // the empty root is structurally skipped, never an error and never a row
                }
                $sections[$section][$root] = $value;
            }
        }

        $overrides = [];

        foreach ($sections['overrides'] as $root => $path) {
            is_string($path) && $path !== ''
                ? $overrides[$root] = $path
                : $errors[] = "{$file}: overrides.{$root} must be a package-relative path";
        }

        $external = [];

        foreach ($sections['external'] as $root => $url) {
            is_string($url) && preg_match('#^https?://#', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false
                ? $external[$root] = $url
                : $errors[] = "{$file}: external.{$root} must be an http(s) URL";
        }

        $exempt = [];

        foreach ($sections['exempt'] as $root => $entry) {
            $reason = is_array($entry) && is_string($entry['reason'] ?? null) ? trim($entry['reason']) : '';
            $pointer = is_array($entry) && is_string($entry['pointer'] ?? null) && trim($entry['pointer']) !== '' ? trim($entry['pointer']) : null;

            $reason !== ''
                ? $exempt[$root] = ['reason' => $reason, 'pointer' => $pointer]
                : $errors[] = "{$file}: exempt.{$root} must carry a non-empty `reason`";
        }

        foreach (array_count_values([...array_keys($overrides), ...array_keys($external), ...array_keys($exempt)]) as $root => $count) {
            if ($count > 1) {
                $errors[] = "{$file}: `{$root}` appears in more than one section — a root has exactly one doc pointer";
            }
        }

        return new self($overrides, $external, $exempt, $errors);
    }

    /** @return list<string> every root this manifest names, each exactly once per section */
    public function roots(): array
    {
        return array_values(array_unique([...array_keys($this->overrides), ...array_keys($this->external), ...array_keys($this->exempt)]));
    }
}
