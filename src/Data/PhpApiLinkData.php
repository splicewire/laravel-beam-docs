<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * A named class-like (a parent, interface or trait): `published` says whether the reference has a page for it.
 *
 * @api
 */
#[Title('PHP API link')]
#[TypeScript]
class PhpApiLinkData extends BeamData
{
    public function __construct(
        public string $fqn,
        public bool $published,
    ) {}
}
