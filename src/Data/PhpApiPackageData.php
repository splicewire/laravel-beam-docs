<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/** One generated package in the PHP API index: its resolved version (null for a path package) and published symbols. */
#[Title('PHP API package')]
#[TypeScript]
class PhpApiPackageData extends BeamData
{
    public function __construct(
        public string $name,
        public ?string $version,
        public int $published,
        /** @var list<PhpApiSymbolRowData> */
        public array $symbols,
    ) {}

    /** @return array<string, list<string>> 02b: a list, empty allowed ({@see PhpApiSymbolData::rules()}). */
    public static function rules(): array
    {
        return ['symbols' => ['present', 'array', 'list']];
    }
}
