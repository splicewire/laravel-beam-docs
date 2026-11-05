<?php

namespace Splicewire\Beam\Docs\Registries;

use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavNode;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Docs\Data\RegistryIndexAvailability;
use Splicewire\Beam\Docs\Data\RegistryIndexData;
use Splicewire\Beam\Docs\Data\RegistryIndexGroupData;
use Splicewire\Beam\Docs\Data\RegistryIndexRootData;
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
 * `beam.docs.registries.nav.widget` picks how the section is drawn (docs-feedback ticket 07). `links`,
 * the default, is the list above. `index` puts the lead pages first and then one
 * {@see RegistryIndexNavNode}: the same groups as data ({@see RegistryIndexData}, availability as a
 * value), for a client that maps the node's kind to a widget, with the `links` list as its children
 * so a client that does not know the kind still draws a list. The default stays `links` because a
 * host's built client can lag its server (a stale bundle draws an unknown kind as a heading over
 * cluster links, losing the roots), so a host opts in once its bundle carries the renderer.
 *
 * Without a catalog, or without a projected registries node, the tree is returned unchanged: the
 * nav degrades to the entries-table answer, never to an error.
 */
class RegistryDocsNav
{
    /** One link per root under its group's heading: the nav as it has always been drawn. */
    public const WIDGET_LINKS = 'links';

    /** One {@see RegistryIndexNavNode} whose kind a client maps to a search-and-tree widget. */
    public const WIDGET_INDEX = 'index';

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
     * The section's children in the configured widget mode: `links` (the default) is one link per
     * root under its group's heading; `index` is the lead pages followed by one
     * {@see RegistryIndexNavNode} carrying the same groups as data, with those links as its children.
     *
     * @param  array<int, NavNode>  $served  the projected (already gated) children of the section
     * @param  array<string, CatalogRow>  $rows
     * @return array<int, NavNode>
     */
    private function section(array $served, string $anchor, array $rows): array
    {
        [$groups, $unlisted] = $this->groups($served, $anchor, $rows);
        $links = [...array_map($this->groupLink(...), $groups), ...$unlisted];

        if ($this->widget() !== self::WIDGET_INDEX) {
            return $links;
        }

        $index = [...$groups];

        if ($unlisted !== []) {
            $index[] = new RegistryIndexGroupData(
                key: '',
                title: (string) config('beam.docs.registries.nav.unlisted_title', 'On this host'),
                href: null,
                roots: array_values(array_map(fn (NavNode $node): RegistryIndexRootData => new RegistryIndexRootData(
                    root: $node->title,
                    href: $node->href,
                    availability: RegistryIndexAvailability::Served,
                    note: null,
                ), $unlisted)),
            );
        }

        return [new RegistryIndexNavNode(
            title: (string) config('beam.docs.registries.nav.index_title', 'Registry index'),
            index: new RegistryIndexData(groups: $index, total: count($rows)),
            children: $links,
        )];
    }

    /** `links` or `index`, from `beam.docs.registries.nav.widget`; anything else reads as `links`. */
    public function widget(): string
    {
        return config('beam.docs.registries.nav.widget') === self::WIDGET_INDEX ? self::WIDGET_INDEX : self::WIDGET_LINKS;
    }

    /**
     * The catalog grouped for this host, the one structure both widget modes are drawn from: the
     * top-level groups (configured headings first, then the remaining groups by key) and the served
     * pages the catalog does not list.
     *
     * @param  array<int, NavNode>  $served
     * @param  array<string, CatalogRow>  $rows
     * @return array{0: list<RegistryIndexGroupData>, 1: list<NavNode>}
     */
    private function groups(array $served, string $anchor, array $rows): array
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
        $members = [];
        $claimed = [];

        foreach ($rows as $root => $row) {
            $key = $place[$root] ?? $this->groupOf($root, array_keys($clusters));
            $path = $this->trim($anchor.'/'.$root);
            $members[$key][] = $this->rootEntry($root, $row, $byPath[$path] ?? null);
            $claimed[$path] = true;
        }

        /** @var array<string, RegistryIndexGroupData> $nodes */
        $nodes = [];

        foreach ($members as $key => $roots) {
            usort($roots, fn (RegistryIndexRootData $a, RegistryIndexRootData $b): int => strcmp($a->root, $b->root));
            $path = $this->trim($anchor.'/'.$key);
            $page = $byPath[$path] ?? null;
            $claimed[$path] = true;
            $title = $page?->title ?? ($clusters[$key]['title'] ?? $titles[$key] ?? $key);
            $nodes[$key] = new RegistryIndexGroupData(key: (string) $key, title: $title, href: $page?->href, roots: $roots);
        }

        // A cluster inside another cluster (`music.transcribe.stage` in `music`) nests under it,
        // deepest first, after the parent's own roots.
        $keys = array_map('strval', array_keys($nodes));
        usort($keys, fn (string $a, string $b): int => substr_count($b, '.') <=> substr_count($a, '.'));

        foreach ($keys as $key) {
            foreach ($keys as $parent) {
                if ($parent !== $key && isset($nodes[$parent], $clusters[$key], $clusters[$parent]) && str_starts_with($key, $parent.'.')) {
                    $nodes[$parent]->groups[] = $nodes[$key];
                    unset($nodes[$key]);

                    break;
                }
            }
        }

        ksort($nodes);
        $sections = [];

        foreach ((array) config('beam.docs.registries.nav.groupings', []) as $heading => $prefixes) {
            $gathered = [];

            foreach ($nodes as $key => $node) {
                foreach ((array) $prefixes as $prefix) {
                    if (is_string($prefix) && ($key === $prefix || str_starts_with((string) $key, $prefix.'.'))) {
                        $gathered[] = $node;
                        unset($nodes[$key]);

                        break;
                    }
                }
            }

            if ($gathered !== []) {
                $sections[] = new RegistryIndexGroupData(key: (string) $heading, title: (string) $heading, href: null, groups: $gathered);
            }
        }

        ksort($nodes);
        $unlisted = array_values(array_filter($served, fn (NavNode $node): bool => $node->href === null || ! isset($claimed[$this->trim($node->href)])));

        return [[...$sections, ...array_values($nodes)], $unlisted];
    }

    /** A group as the `links` mode draws it: a heading over its roots' links, then its nested groups. */
    private function groupLink(RegistryIndexGroupData $group): NavNode
    {
        return NavLink::make(
            title: $group->title,
            href: $group->href,
            children: [
                ...array_map($this->rootLink(...), $group->roots),
                ...array_map($this->groupLink(...), $group->groups),
            ],
        );
    }

    /** A root as the `links` mode draws it: titled by its root, with the annotation in the title. */
    private function rootLink(RegistryIndexRootData $entry): NavNode
    {
        return NavLink::make(title: $entry->note === null ? $entry->root : "{$entry->root} ({$entry->note})", href: $entry->href);
    }

    private function rootEntry(string $root, CatalogRow $row, ?NavNode $served): RegistryIndexRootData
    {
        if ($served !== null) {
            return new RegistryIndexRootData(root: $root, href: $served->href, availability: RegistryIndexAvailability::Served, note: null);
        }

        if ($row->kind === CatalogRow::KIND_EXTERNAL && $row->url !== null) {
            return new RegistryIndexRootData(root: $root, href: $row->url, availability: RegistryIndexAvailability::External, note: null);
        }

        [$availability, $note] = match (true) {
            $row->kind === CatalogRow::KIND_EXEMPT => [RegistryIndexAvailability::Exempt, 'no page: '.($row->exemptionClass ?? 'exempt')],
            $row->kind === null => [RegistryIndexAvailability::NotDocumented, 'not yet documented'],
            // The owning package is not installed here: the plainest answer, and one that holds
            // even where the runtime index cannot be read (unbaked, mid-boot).
            $row->owner !== null && ! isset(($this->installed ??= $this->packages->installed())[$row->owner]) => [RegistryIndexAvailability::NotAvailable, 'not available on this host'],
            $this->availability->knownLocally($root) === false => [RegistryIndexAvailability::NotAvailable, 'not available on this host'],
            default => [RegistryIndexAvailability::NotPublished, 'not published on this host'],
        };

        return new RegistryIndexRootData(root: $root, href: $this->deepLink($root), availability: $availability, note: $note);
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
