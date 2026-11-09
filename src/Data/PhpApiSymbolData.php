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
 *
 * @api
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
     * Every declared list is a LIST (read by {@see PhpApiArtifact} through `validateAndCreate()`):
     *
     * - the plain string lists may be EMPTY: present and a list of strings, not `required` (which Laravel fails on `[]`);
     * - 02b (slice 2 final review 8b7f533b): the structured collections add Laravel's `list` beside the `present|array`
     *   laravel-data infers, because `array` admits string keys and laravel-data keeps them through hydration and
     *   transformation, so valid items under named keys would be served as a JSON object. They are rejected, never
     *   normalized. Only the key's own rule is overwritten; the element rules (`methods.*.…`) are still inferred.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $list = ['present', 'array', 'list'];

        return [
            'modifiers' => $list, 'modifiers.*' => ['string'],
            'doc' => $list, 'doc.*' => ['string'],
            'implementers' => $list, 'implementers.*' => ['string'],
            'templates' => $list, 'extends' => $list, 'implements' => $list, 'traits' => $list, 'attributes' => $list,
            'tags' => $list, 'constants' => $list, 'cases' => $list, 'properties' => $list, 'methods' => $list,
            'see' => $list, 'inherited' => $list,
        ];
    }
}
