<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** One catalog root in the registry index: its dotted name, where it links and how it stands on this host. */
#[TypeScript]
class RegistryIndexRootData extends BeamData
{
    public function __construct(
        /** The dotted root, verbatim (`beam.particle.resources`). */
        public string $root,
        /** The served page, the external pointer, or the deep-link route that answers the annotation. */
        public ?string $href,
        public RegistryIndexAvailability $availability,
        /** The annotation the per-link nav puts in the title ("not available on this host"); null when served or external. */
        public ?string $note,
    ) {}
}
