<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** The declared `input:` of `beam-docs.php-api-index`: none; the whole index of the generated artifact is returned. */
#[Title('PHP API index input')]
#[TypeScript]
class PhpApiIndexInputData extends BeamData
{
    public function __construct() {}
}
