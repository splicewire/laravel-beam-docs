<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** Where a miss goes (DOC-9): the related product's docs, carrying the same query. */
#[Title('Docs search fallback')]
#[TypeScript]
class DocsSearchFallbackData extends BeamData
{
    public function __construct(public string $label, public string $href) {}
}
