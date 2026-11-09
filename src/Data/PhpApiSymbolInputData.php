<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The declared `input:` of `beam-docs.php-api-symbol`: the FQN to look up in the package indexes. It is a key, never a
 * path; an FQN no index lists is the uniform 404.
 *
 * @api
 */
#[Title('PHP API symbol input')]
#[TypeScript]
class PhpApiSymbolInputData extends BeamData
{
    public function __construct(
        public ?string $fqn = null,
    ) {}
}
