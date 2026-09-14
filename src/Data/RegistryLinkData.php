<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\LaravelData\Data;

class RegistryLinkData extends Data
{
    public function __construct(public ?string $url) {}
}
