<?php

namespace Splicewire\Beam\Docs\Data;

use Splicewire\Beam\Brand\BrandData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * Everything a docs page's packaged header draws (docs-walkthrough DM4, DOC-7): the product's brand, the docs home, where
 * "back" goes, the root's surfaces and the related products. Read once on the server by {@see \Splicewire\Beam\Docs\Chrome\DocsChrome}
 * and carried on every docs page as `docsChrome`, so no host spells a header. Search and the appearance toggle are header
 * SLOTS that DOCS-14 and DOCS-13 fill (lead ruling 3, 2026-10-06), not fields here.
 */
#[TypeScript]
class DocsChromeData extends BeamData
{
    public function __construct(
        public BrandData $brand,
        /** The docs root's own URL. */
        public string $home,
        /** The product's site page: its brand's `home`, else `/` (lead ruling 2; a docs page is not in an app realm, so
         * M2's `HostRealmsData.back` is always null here). */
        public string $back,
        /** @var list<DocsSurfaceData> */
        public array $surfaces,
        /** @var list<DocsRelatedData> */
        public array $related,
    ) {}
}
