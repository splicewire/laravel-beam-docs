<?php

namespace Splicewire\Beam\Docs\Data;

use Splicewire\Beam\Data\BeamData;

/** One visible entry of a registry and who registered it — a row of the registrant panel (registry-docs ticket 03). */
class RegistryRegistrantData extends BeamData
{
    public function __construct(
        /** The entry's full key, root included. */
        public string $key,
        /** The `$by` its registration wrote. */
        public string $registrant,
    ) {}
}
