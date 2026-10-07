<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Description;
use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The declared `input:` of `docs.search` (docs-walkthrough DM6, DOCS-14): the query and the docs root it is scoped to
 * (the header sends `docsChrome.home`). Validated by ParticleOperationController before the handler runs.
 */
#[Title('Docs search')]
#[TypeScript]
class DocsSearchInputData extends BeamData
{
    public function __construct(
        #[Description('Words to search for in the documentation.'), Min(1), Max(200)]
        public string $q,
        #[Description('Documentation root URL that scopes the search.'), Max(500)]
        public string $root,
    ) {}
}
