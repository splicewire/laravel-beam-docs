<?php

namespace Splicewire\Beam\Docs\Localization;

use Splicewire\Beam\Data\BeamData;

class DocsErrorData extends BeamData
{
    public function __construct(public string $message, public string $code) {}
}
