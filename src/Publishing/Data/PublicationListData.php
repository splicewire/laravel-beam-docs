<?php

namespace Splicewire\Beam\Docs\Publishing\Data;

use Splicewire\Beam\Data\BeamData;

class PublicationListData extends BeamData
{
    /** @param array<PublicationData> $data */
    public function __construct(public array $data) {}
}
