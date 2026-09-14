<?php

namespace Splicewire\Beam\Docs\Data;

use Splicewire\Beam\Data\BeamData;

class RegistryLinkResponseData extends BeamData
{
    public function __construct(public RegistryLinkData $data) {}
}
