<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * A published symbol in a package's index: `kind` is class, interface, trait or enum; `summary` its first kept paragraph.
 *
 * @api
 */
#[Title('PHP API symbol row')]
#[TypeScript]
class PhpApiSymbolRowData extends BeamData
{
    public function __construct(
        public string $fqn,
        public string $kind,
        public ?string $summary,
    ) {}
}
