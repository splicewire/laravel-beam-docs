<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\LaravelData\Data;

class RegistryLinkResponseData extends Data
{
    public function __construct(public RegistryLinkData $data) {}
}
