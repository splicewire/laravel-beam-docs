<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** The declared `output:` of `docs.search` (DM6): the reader's hits, best first, and where a miss goes. */
#[TypeScript]
class DocsSearchResultsData extends BeamData
{
    public function __construct(
        /** @var list<DocsSearchResultData> */
        public array $results,
        public ?DocsSearchFallbackData $fallback,
    ) {}
}
