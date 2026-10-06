<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** One surface of a docs root (Guides' siblings: API, MCP): a `SpreadTemplate` child, labelled by its row title. */
#[TypeScript]
class DocsSurfaceData extends BeamData
{
    public function __construct(public string $label, public string $href) {}
}
