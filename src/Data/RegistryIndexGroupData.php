<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One group of the registry index: a catalog cluster, a first-segment group, or a configured heading
 * (`beam.docs.registries.nav.groupings`) that gathers groups and holds no roots of its own. A cluster
 * inside another cluster sits in its parent's `groups`, after the parent's own roots.
 */
#[TypeScript]
class RegistryIndexGroupData extends BeamData
{
    /**
     * @param  list<RegistryIndexRootData>  $roots
     * @param  list<RegistryIndexGroupData>  $groups
     */
    public function __construct(
        /** The group key (a cluster or first segment), or the heading itself for a configured heading. */
        public string $key,
        public string $title,
        /** The group's served cluster page, or null. */
        public ?string $href,
        /** @var list<RegistryIndexRootData> */
        #[DataCollectionOf(RegistryIndexRootData::class)]
        public array $roots = [],
        /** @var list<RegistryIndexGroupData> */
        #[DataCollectionOf(RegistryIndexGroupData::class)]
        public array $groups = [],
    ) {}
}
