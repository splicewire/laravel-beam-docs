<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** A `@template` parameter: `variance` is invariant, covariant or contravariant. */
#[Title('PHP API template')]
#[TypeScript]
class PhpApiTemplateData extends BeamData
{
    public function __construct(
        public string $name,
        public ?string $bound,
        public ?string $default,
        public string $variance,
    ) {}
}
