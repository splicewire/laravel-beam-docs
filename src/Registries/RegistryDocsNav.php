<?php

namespace Splicewire\Beam\Docs\Registries;

use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavNode;
use Rushing\DataNav\NavTree;
use Throwable;

/**
 * The registry reference's navigation (registry-docs ticket 01, amended ruling 7): the committed
 * catalog GROUPED BY CLUSTER and annotated with this host's local availability, composed into the
 * already-gated `site` projection that `NavProjector` builds from the entries table (ADR-0210).
 *
 * The entries table answers "which registry pages does this host serve, and may this reader see
 * them" (the projector's publish and access passes have already run). The catalog answers "which
 * roots does the product document, and how do they group". Neither alone is the nav: a host's bake
 * lacks roots the product documents, and the catalog knows nothing about publish state or access.
 *
 * Composition, under the node at `{segment}/registries` only, everything else untouched:
 *
 *  - every catalog root becomes one link titled by its dotted root. A page this host serves keeps
 *    its projected href. An external pointer links out. Anything else is SHOWN, not hidden: its
 *    title carries the reason ("not available on this host", or the exemption class) and it links
 *    to the deep-link route, which answers the same annotation as JSON;
 *  - roots group under the longest catalog cluster that prefixes them (segment-wise), otherwise
 *    under their first segment. `beam.docs.registries.nav.place` moves a root into another group
 *    (the `workflow` root, outside the `beam.workflows.` prefix, sits with that cluster on www);
 *  - a cluster inside another cluster nests under it; `beam.docs.registries.nav.titles` names a
 *    group that has no cluster page to take a title from;
 *  - `beam.docs.registries.nav.groupings` gathers several groups under one titled heading that is
 *    not a cluster ("Tower operations" spans `conduit`, `determination`, `retrieval` and more).
 *    Headings come first, in configured order; the remaining groups follow, sorted by key;
 *  - `beam.docs.registries.nav.lead` names projected sections (by public path) copied in as the
 *    section's first children, so conceptual pages such as a host's foundations sit in the same
 *    tree as the reference they introduce. A path the projection does not have is skipped;
 *  - a served page under the section that the catalog does not list (an app-local root's page on
 *    a host) is kept, after the catalog's groups.
 *
 * Without a catalog, or without a projected registries node, the tree is returned unchanged: the
 * nav degrades to the entries-table answer, never to an error.
 */
class RegistryDocsNav
{
    public function __construct(
        private RegistryDocsCatalog $catalog,
        private RegistryDocsAvailability $availability,
        private FamilyPackages $packages,
    ) {}

    /** @var array<string, string>|null */
    private ?array $installed = null;

    public function compose(NavTree $tree): NavTree
    {
        try {
            $rows = $this->catalog->rows();
        } catch (Throwable) {
            return $tree;
        }

        if ($rows === []) {
            return $tree;
        }

        $anchor = $this->anchorPath();
        $lead = [];

        foreach ((array) config('beam.docs.registries.nav.lead', []) as $path) {
            if (is_string($path) && ($node = $this->find($tree->items, $path)) !== null) {
                $lead[] = $node;
            }
        }

        $items = $this->rewrite($tree->items, $anchor, $rows, $found, $lead);

        return $found ? new NavTree(items: $items) : $tree;
    }

    /** The public path of the registry section on this host: `{segment}/registries`. */
    public function anchorPath(): string
    {
        return rtrim((string) config('beam.docs.segment', '/docs'), '/').'/registries';
    }

    /**
     * @param  array<int, NavNode>  $items
     * @param  array<string, CatalogRow>  $rows
     * @return array<int, NavNode>
     */
    private function rewrite(array $items, string $anchor, array $rows, ?bool &$found, array $lead = []): array
    {
        $found ??= false;

        foreach ($items as $i => $item) {
            // Copy on write: the projection's nodes are never mutated, so a cached tree stays the
            // projector's own answer and a second compose starts from it, not from this one.
            $item = clone $item;

            if (! $found && $this->samePath($item->href, $anchor)) {
                $found = true;
                $item->children = [...$lead, ...$this->section($item->children, $anchor, $rows)];
            } elseif (! $found && $item->children !== []) {
                $item->children = $this->rewrite($item->children, $anchor, $rows, $found, $lead);
            }

            $items[$i] = $item;
        }

        return $items;
    }

    /**
     * @param  array<int, NavNode>  $served  the projected (already gated) children of the section
     * @param  array<string, CatalogRow>  $rows
     * @return array<int, NavNode>
     */
    private function section(array $served, string $anchor, array $rows): array
    {
        $byPath = [];

        foreach ($served as $node) {
            if ($node->href !== null) {
                $byPath[$this->trim($node->href)] = $node;
            }
        }

        $clusters = $this->catalog->clusters();
        $place = array_filter((array) config('beam.docs.registries.nav.place', []), 'is_string');
        $titles = array_filter((array) config('beam.docs.registries.nav.titles', []), 'is_string');
        $groups = [];
        $claimed = [];

        foreach ($rows as $root => $row) {
            $key = $place[$root] ?? $this->groupOf($root, array_keys($clusters));
            $path = $this->trim($anchor.'/'.$root);
            $groups[$key][] = $this->rootLink($root, $row, $byPath[$path] ?? null);
            $claimed[$path] = true;
        }

        $nodes = [];

        foreach ($groups as $key => $links) {
            usort($links, fn (NavNode $a, NavNode $b): int => strcmp($a->title, $b->title));
            $path = $this->trim($anchor.'/'.$key);
            $page = $byPath[$path] ?? null;
            $claimed[$path] = true;
            $title = $page?->title ?? ($clusters[$key]['title'] ?? $titles[$key] ?? $key);
            $nodes[$key] = NavLink::make(title: $title, href: $page?->href, children: $links);
        }

        // A cluster inside another cluster (`music.transcribe.stage` in `music`) nests under it,
        // deepest first, after the parent's own roots.
        $keys = array_keys($nodes);
        usort($keys, fn (string $a, string $b): int => substr_count($b, '.') <=> substr_count($a, '.'));

        foreach ($keys as $key) {
            foreach ($keys as $parent) {
                if ($parent !== $key && isset($nodes[$parent], $clusters[$key], $clusters[$parent]) && str_starts_with($key, $parent.'.')) {
                    $nodes[$parent]->children[] = $nodes[$key];
                    unset($nodes[$key]);

                    break;
                }
            }
        }

        ksort($nodes);
        $sections = [];

        foreach ((array) config('beam.docs.registries.nav.groupings', []) as $heading => $prefixes) {
            $members = [];

            foreach ($nodes as $key => $node) {
                foreach ((array) $prefixes as $prefix) {
                    if (is_string($prefix) && ($key === $prefix || str_starts_with($key, $prefix.'.'))) {
                        $members[] = $node;
                        unset($nodes[$key]);

                        break;
                    }
                }
            }

            if ($members !== []) {
                $sections[] = NavLink::make(title: (string) $heading, children: $members);
            }
        }

        ksort($nodes);
        $unlisted = array_values(array_filter($served, fn (NavNode $node): bool => $node->href === null || ! isset($claimed[$this->trim($node->href)])));

        return [...$sections, ...array_values($nodes), ...$unlisted];
    }

    private function rootLink(string $root, CatalogRow $row, ?NavNode $served): NavNode
    {
        if ($served !== null) {
            return NavLink::make(title: $root, href: $served->href);
        }

        if ($row->kind === CatalogRow::KIND_EXTERNAL && $row->url !== null) {
            return NavLink::make(title: $root, href: $row->url);
        }

        $note = match (true) {
            $row->kind === CatalogRow::KIND_EXEMPT => 'no page: '.($row->exemptionClass ?? 'exempt'),
            $row->kind === null => 'not yet documented',
            // The owning package is not installed here: the plainest answer, and one that holds
            // even where the runtime index cannot be read (unbaked, mid-boot).
            $row->owner !== null && ! isset(($this->installed ??= $this->packages->installed())[$row->owner]) => 'not available on this host',
            $this->availability->knownLocally($root) === false => 'not available on this host',
            default => 'not published on this host',
        };

        return NavLink::make(title: "{$root} ({$note})", href: $this->deepLink($root));
    }

    private function deepLink(string $root): ?string
    {
        try {
            return route('beam.docs.registries.show', ['root' => $root], false);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The group a root falls in: the longest catalog cluster that is a segment-wise prefix of it
     * (a cluster's own root counts as a member), otherwise its first segment.
     *
     * @param  list<string>  $clusters
     */
    private function groupOf(string $root, array $clusters): string
    {
        $best = null;

        foreach ($clusters as $prefix) {
            if (($root === $prefix || str_starts_with($root, $prefix.'.')) && ($best === null || strlen($prefix) > strlen($best))) {
                $best = $prefix;
            }
        }

        return $best ?? explode('.', $root)[0];
    }

    /**
     * The projected node at `$path`, anywhere in the tree, or null.
     *
     * @param  array<int, NavNode>  $items
     */
    private function find(array $items, string $path): ?NavNode
    {
        foreach ($items as $item) {
            if ($this->samePath($item->href, $path)) {
                return $item;
            }

            if (($nested = $this->find($item->children, $path)) !== null) {
                return $nested;
            }
        }

        return null;
    }

    private function samePath(?string $a, string $b): bool
    {
        return $a !== null && $this->trim($a) === $this->trim($b);
    }

    private function trim(string $href): string
    {
        $path = (string) (parse_url($href, PHP_URL_PATH) ?? $href);

        return strlen($path) > 1 ? rtrim($path, '/') : $path;
    }
}
