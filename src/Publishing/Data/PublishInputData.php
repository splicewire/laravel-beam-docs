<?php

namespace Splicewire\Beam\Docs\Publishing\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Docs\Publishing\Validation\ReleaseVersion;

#[TypeScript]
class PublishInputData extends BeamData
{
    public function __construct(
        #[Title('Release version'), Max(128), ReleaseVersion]
        public string $version,
    ) {}
}
