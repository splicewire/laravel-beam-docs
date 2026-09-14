<?php

namespace Splicewire\Beam\Docs\Publishing\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Splicewire\Beam\Data\BeamData;

class PublishInputData extends BeamData
{
    public function __construct(
        #[Max(128), Regex('/\A[A-Za-z0-9][A-Za-z0-9._+-]*\z/')]
        public string $version,
    ) {}
}
