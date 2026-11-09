<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * A resolved `@see` / `{@see}` target (`Class` or `Class::member`); `published` when the reference can link it.
 *
 * @api
 */
#[Title('PHP API see link')]
#[TypeScript]
class PhpApiSeeData extends BeamData
{
    public function __construct(
        public string $target,
        public bool $published,
    ) {}
}
