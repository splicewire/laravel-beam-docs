<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** Another product this install documents, for the docs product switcher (docs-walkthrough C-6, DOC-16). */
#[TypeScript]
class DocsRelatedData extends BeamData
{
    public function __construct(
        public string $key,
        public string $name,
        public ?string $tagline,
        /** The product's own site page. */
        public string $href,
        /** The product's docs root, when declared (`beam.brands.{key}.docs`). */
        public ?string $docs,
    ) {}
}
