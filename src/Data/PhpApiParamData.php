<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * A method parameter as declared; `type` and `default` are printed source, null when there is none.
 *
 * @api
 */
#[Title('PHP API parameter')]
#[TypeScript]
class PhpApiParamData extends BeamData
{
    public function __construct(
        public string $name,
        public ?string $type,
        public ?string $default,
        public bool $byRef,
        public bool $variadic,
        public bool $promoted,
        /** @var list<PhpApiAttributeData> */
        public array $attributes,
    ) {}

    /** @return array<string, list<string>> 02b: a list, empty allowed ({@see PhpApiSymbolData::rules()}). */
    public static function rules(): array
    {
        return ['attributes' => ['present', 'array', 'list']];
    }
}
