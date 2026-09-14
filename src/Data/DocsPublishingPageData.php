<?php

namespace Splicewire\Beam\Docs\Data;

use Splicewire\Beam\Data\BeamData;

class DocsPublishingPageData extends BeamData
{
    public function __construct(public string $publicationsEndpoint, public ?string $docsUrl) {}
}
