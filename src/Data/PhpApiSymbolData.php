<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Docs\PhpApi\PhpApiArtifact;

/**
 * The declared `output:` of `beam-docs.php-api-symbol` (php-api-reference slice 2): one published symbol's generated
 * document, read from the path its package index holds. Prose is already filtered for the artifact's audience;
 * `comment` (the whole docblock) is present only for `internal`. `file` is relative to the package root.
 */
#[Title('PHP API symbol')]
#[TypeScript]
class PhpApiSymbolData extends BeamData
{
    public function __construct(
        public string $fqn,
        public string $package,
        public string $kind,
        /** @var list<string> */
        public array $modifiers,
        /** @var list<PhpApiTemplateData> */
        public array $templates,
        /** @var list<PhpApiLinkData> */
        public array $extends,
        /** @var list<PhpApiLinkData> */
        public array $implements,
        /** @var list<PhpApiLinkData> */
        public array $traits,
        public ?string $backingType,
        /** @var list<PhpApiAttributeData> */
        public array $attributes,
        /** @var list<string> */
        public array $doc,
        /** @var list<PhpApiTagData> */
        public array $tags,
        public string $file,
        public int $line,
        /** @var list<PhpApiMemberData> */
        public array $constants,
        /** @var list<PhpApiMemberData> */
        public array $cases,
        /** @var list<PhpApiMemberData> */
        public array $properties,
        /** @var list<PhpApiMemberData> */
        public array $methods,
        /** @var list<PhpApiSeeData> */
        public array $see,
        /** @var list<PhpApiInheritedData> */
        public array $inherited,
        /** @var list<string> */
        public array $implementers,
        public Optional|string $comment = new Optional,
    ) {}

    /**
     * The plain string lists the generator writes may be EMPTY: present and a list of strings, not `required` (which
     * Laravel fails on `[]`). Read by {@see PhpApiArtifact} through `validateAndCreate()`.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'modifiers' => ['present', 'array', 'list'], 'modifiers.*' => ['string'],
            'doc' => ['present', 'array', 'list'], 'doc.*' => ['string'],
            'implementers' => ['present', 'array', 'list'], 'implementers.*' => ['string'],
        ];
    }
}
