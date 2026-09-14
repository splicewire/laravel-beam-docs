<?php

namespace Splicewire\Beam\Docs\Publishing\Data;

use Splicewire\Beam\Data\BeamData;

class PublicationResponseData extends BeamData
{
    public function __construct(public PublicationData $data) {}
}
