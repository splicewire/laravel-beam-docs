<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** One docs search hit: a page, or the root's API reference surface (`kind: reference`, C-8). */
#[TypeScript]
class DocsSearchResultData extends BeamData
{
    public function __construct(
        public string $title,
        public string $href,
        /** The titles above it under the docs root, e.g. ['Build', 'Extensions']. */
        public array $trail,
        public ?string $excerpt,
        /** `page` | `reference`. */
        public string $kind,
    ) {}
}
