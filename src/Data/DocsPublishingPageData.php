<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\LaravelData\Data;

class DocsPublishingPageData extends Data
{
    public function __construct(public string $publicationsEndpoint, public ?string $docsUrl) {}
}
