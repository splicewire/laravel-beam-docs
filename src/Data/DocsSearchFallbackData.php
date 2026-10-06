<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** Where a miss goes (DOC-9): the related product's docs, carrying the same query. */
#[TypeScript]
class DocsSearchFallbackData extends BeamData
{
    public function __construct(public string $label, public string $href) {}
}
