<?php

namespace Splicewire\Beam\Docs\Data;

use Splicewire\Beam\Data\BeamData;

class RegistryLinkData extends BeamData
{
    public function __construct(public ?string $url) {}
}
