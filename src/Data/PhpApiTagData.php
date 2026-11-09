<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * A structured docblock tag (`@param`, `@return`, `@var`, `@throws`, `@extends`, `@implements`, `@uses`,
 * `@deprecated`). Absent parts are absent on the wire, as in the artifact; a withheld description is absent too.
 *
 * @api
 */
#[Title('PHP API tag')]
#[TypeScript]
class PhpApiTagData extends BeamData
{
    public function __construct(
        public string $tag,
        public Optional|string $type = new Optional,
        public Optional|string $name = new Optional,
        public Optional|string $description = new Optional,
    ) {}
}
