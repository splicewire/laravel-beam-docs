<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

#[TypeScript]
class DocsPublishingPageData extends BeamData
{
    public function __construct(public string $publicationsEndpoint, public ?string $docsUrl) {}
}
