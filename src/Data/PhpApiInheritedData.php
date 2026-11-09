<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * An inherited member (from a published parent or trait), by effective name and visibility. An adapted trait method (an
 * alias or a visibility change) also carries its full effective `declaration`; a private original is never named.
 */
#[Title('PHP API inherited member')]
#[TypeScript]
class PhpApiInheritedData extends BeamData
{
    public function __construct(
        public string $kind,
        public string $name,
        public string $from,
        public ?string $visibility,
        public Optional|PhpApiMemberData $declaration = new Optional,
    ) {}
}
