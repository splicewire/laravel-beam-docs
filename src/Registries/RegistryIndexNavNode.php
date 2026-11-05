<?php

namespace Splicewire\Beam\Docs\Registries;

use Rushing\DataNav\NavLocked;
use Rushing\DataNav\NavNode;
use Schemastud\DataSchemas\Contracts\SchemaIdentity;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Splicewire\Beam\Docs\Data\RegistryIndexData;
use Splicewire\Beam\Docs\DocsUxServiceProvider;

/**
 * The registry reference drawn as one widget instead of one link per root (docs-feedback ticket 07).
 *
 * {@see RegistryDocsNav} emits this node under `{segment}/registries` when the host sets
 * `beam.docs.registries.nav.widget` to `index`. Its `index` is the catalog, grouped and annotated
 * ({@see RegistryIndexData}); a client that maps the kind to a component draws it (beam-docs draws a
 * search box above a cluster tree). Its `children` are the same grouped links the `links` mode
 * emits, so a client that does not know the kind still gets a labelled list, and anything that walks
 * the tree for hrefs (a breadcrumb, a link audit) still finds every page.
 *
 * The kind is registered in `data-nav.kinds` by {@see DocsUxServiceProvider},
 * so the node keeps its `index` through a `toArray()` → `NavTree::from()` round trip.
 */
class RegistryIndexNavNode extends NavNode implements SchemaIdentity
{
    public const KIND = 'beam-docs/registry-index';

    /**
     * @param  array<int, NavNode>  $children
     */
    public function __construct(
        string $title,
        public ?RegistryIndexData $index = null,
        ?string $href = null,
        ?string $match = null,
        #[DataCollectionOf(NavNode::class)]
        array $children = [],
        bool $active = false,
        bool $activeTrail = false,
        ?string $icon = null,
        ?string $routeName = null,
        ?NavLocked $locked = null,
        ?string $zone = null,
    ) {
        parent::__construct(
            kind: self::KIND,
            title: $title,
            href: $href,
            match: $match,
            children: $children,
            active: $active,
            activeTrail: $activeTrail,
            icon: $icon,
            routeName: $routeName,
            locked: $locked,
            zone: $zone,
        );
    }

    public static function schemaName(): string
    {
        return self::KIND;
    }

    public static function schemaVersion(): int
    {
        return 1;
    }
}
