<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Docs\Registries\RegistryIndexNavNode;

/**
 * The registry reference as one nav widget's data (docs-feedback ticket 07): the committed catalog,
 * grouped exactly as the per-link nav groups it, with every root's availability on this host as a
 * value. It rides on a {@see RegistryIndexNavNode} so a client that
 * maps the node's kind to a widget (beam-ux `RealmNav` `kinds`) can draw a search and a tree.
 */
#[TypeScript]
class RegistryIndexData extends BeamData
{
    /** @param  list<RegistryIndexGroupData>  $groups */
    public function __construct(
        /** @var list<RegistryIndexGroupData> */
        #[DataCollectionOf(RegistryIndexGroupData::class)]
        public array $groups = [],
        /** How many catalog roots the groups hold. */
        public int $total = 0,
    ) {}
}
