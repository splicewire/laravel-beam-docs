<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** A PHP attribute as written: its resolved name and each argument printed (`name: value` when named). */
#[Title('PHP API attribute')]
#[TypeScript]
class PhpApiAttributeData extends BeamData
{
    public function __construct(
        public string $name,
        /** @var list<string> */
        public array $arguments,
    ) {}

    /** @return array<string, list<string>> `arguments` may be empty: present and a list of strings. */
    public static function rules(): array
    {
        return ['arguments' => ['present', 'array', 'list'], 'arguments.*' => ['string']];
    }
}
