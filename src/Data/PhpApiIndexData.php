<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * The declared `output:` of `beam-docs.php-api-index` (php-api-reference slice 2): the generated artifact's packages and
 * their published symbols, as the artifact's own manifest and indexes hold them. `audience` is the one it was generated
 * for (`public` or `internal`). Rows carry no artifact path: a symbol is read by FQN.
 */
#[Title('PHP API index')]
#[TypeScript]
class PhpApiIndexData extends BeamData
{
    public function __construct(
        public string $audience,
        /** @var list<PhpApiPackageData> */
        public array $packages,
    ) {}

    /** @return array<string, list<string>> 02b: a list, empty allowed ({@see PhpApiSymbolData::rules()}). */
    public static function rules(): array
    {
        return ['packages' => ['present', 'array', 'list']];
    }
}
