<?php

namespace Splicewire\Beam\Docs\Doctor;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Throwable;

/**
 * `docs.link-targets` (docs-walkthrough DOCS-02, rule DOC-6, the STORED half): every same-origin URL in a stored entry
 * body resolves on this host. A path resolves when a route other than the entry renderer claims it, when the
 * renderer's realm resolves it to an entry ({@see EntryPathResolver}), or when it is a file under `public/`. A dead one
 * is a FAIL naming `entry → path`, and so is an `href="#"` placeholder (DOC-6).
 *
 * It reads each entry's STORED body (its particle, decoded by its codec), never a render and never a re-seed, so it runs
 * on every host as the host stands. The RENDERED half is the harness spec `g1-docs-links`. A link to another host is
 * not judged here: only that host can say whether it resolves.
 */
class DocsLinkTargetsAudit implements DoctorAudit
{
    public const CHECK = 'docs.link-targets';

    public function __construct(
        private Router $router,
        private EntryPathResolver $paths,
        private StorageDriverResolver $drivers,
    ) {}

    public function run(): array
    {
        if (! Schema::hasTable((new BeamUxEntry)->getTable())) {
            return [Finding::inconclusive(self::CHECK, 'The entries table does not exist yet; migrate, then re-run.')];
        }

        $host = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $checked = 0;
        $bodies = 0;
        $dead = [];
        foreach (BeamUxEntry::query()->whereNotNull('particle_id')->cursor() as $entry) {
            $source = $this->source($entry);
            if ($source === null) {
                continue;
            }
            $bodies++;
            // DOC-6: no `href="#"`, a placeholder that goes nowhere (an `#anchor` does go somewhere).
            $hashes = preg_match_all('/\bhref\s*=\s*\{?\s*["\']#["\']/', $source);
            if ($hashes > 0) {
                $checked += $hashes;
                $dead[] = ($entry->namespace ? "{$entry->namespace}." : '')."{$entry->slug} → href=\"#\" ×{$hashes}";
            }
            foreach ($this->sameOriginPaths($source, $host) as $path) {
                $checked++;
                if (! $this->resolves($path)) {
                    $dead[] = ($entry->namespace ? "{$entry->namespace}." : '')."{$entry->slug} → {$path}";
                }
            }
        }

        if ($bodies === 0) {
            return [Finding::inconclusive(self::CHECK, 'No entry has a stored body, so there are no links to check.')];
        }
        $counts = sprintf('%d checked · %d dead (in %d stored bodies)', $checked, count($dead), $bodies);
        if ($dead !== []) {
            return [Finding::fail(self::CHECK, "{$counts}. Same-origin links that resolve to no entry, route or file: ".implode(', ', array_unique($dead)).'.')];
        }

        return [Finding::pass(self::CHECK, "{$counts}. Every same-origin link in a stored body resolves.")];
    }

    private function source(BeamUxEntry $entry): ?string
    {
        try {
            $item = $this->drivers->resolve($entry)->read((string) $entry->particle_id);

            return $item === null ? null : $entry->codec()->decode($item->body);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The same-origin paths a body links to: markdown link and image targets, `href`/`src` attributes, and absolute
     * URLs on this host. Fragments and queries are dropped; a bare `#anchor` is not a target.
     *
     * @return list<string>
     */
    private function sameOriginPaths(string $source, string $host): array
    {
        $raw = [];
        preg_match_all('/\]\(\s*<?([^)\s>]+)/', $source, $m);
        $raw = [...$raw, ...$m[1]];
        preg_match_all('/\b(?:href|src)\s*=\s*\{?\s*["\']([^"\']+)["\']/', $source, $m);
        $raw = [...$raw, ...$m[1]];
        if ($host !== '') {
            preg_match_all('#https?://'.preg_quote($host, '#').'(/[^\s"\'`)<>\]]*)#i', $source, $m);
            $raw = [...$raw, ...$m[1]];
        }

        $paths = [];
        foreach ($raw as $url) {
            if (preg_match('#^https?://([^/]+)(/.*)?$#i', $url, $abs)) {
                if (strtolower($abs[1]) !== $host) {
                    continue;
                }
                $url = $abs[2] ?? '/';
            }
            if (! str_starts_with($url, '/') || str_starts_with($url, '//')) {
                continue;
            }
            $path = strtok($url, '#?');
            if ($path !== false && $path !== '') {
                $paths[$path] = true;
            }
        }

        return array_keys($paths);
    }

    private function resolves(string $path): bool
    {
        $route = $this->claimant($path);
        if ($route !== null && ! array_key_exists('beamUxRealm', $route->defaults)) {
            return true;
        }
        $realm = (string) ($route?->defaults['beamUxRealm'] ?? BeamUxEntry::REALM_SITE);
        if ($this->paths->resolve($path, $realm) !== null) {
            return true;
        }

        return is_file(public_path(ltrim($path, '/')));
    }

    private function claimant(string $path): ?Route
    {
        try {
            return $this->router->getRoutes()->match(Request::create($path, 'GET'));
        } catch (Throwable) {
            return null;
        }
    }
}
