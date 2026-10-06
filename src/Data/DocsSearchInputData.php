<?php

namespace Splicewire\Beam\Docs\Data;

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
        #[Min(1), Max(200)]
        public string $q,
        #[Max(500)]
        public string $root,
    ) {}
}
