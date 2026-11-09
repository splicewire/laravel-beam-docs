<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * An inherited member (from a published parent or trait), by effective name and visibility, as 01b writes it:
 *
 * - `abstract` (methods only, `true` or `false`): whether the effective member is still an abstract requirement;
 * - `original` (an alias only): the original method's name, written ONLY when that original is not private;
 * - `declaration` (an adapted trait method: an alias or a visibility change): its full effective declaration.
 *
 * Absent parts are absent on the wire, as in the artifact; a private original is never named.
 *
 * @api
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
        public Optional|bool $abstract = new Optional,
        public Optional|string $original = new Optional,
        public Optional|PhpApiMemberData $declaration = new Optional,
    ) {}
}
