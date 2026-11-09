<?php

namespace Splicewire\Beam\Docs\Data;

use Schemastud\DataSchemas\Attributes\Title;
use Spatie\LaravelData\Optional;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;
use Splicewire\Beam\Data\BeamData;

/**
 * One published member: a constant, enum case, property or method, or an adapted trait method's effective declaration.
 * The fields a kind does not have are absent on the wire, exactly as the generator writes them (a constant has
 * `final`/`type`/`value`; a case `value`; a property `static`/`readonly`/`type`/`default`/`promoted`; a method
 * `static`/`abstract`/`final`/`byRef`/`params`/`returnType`/`templates`/`signature`). `comment` is the whole docblock,
 * written only for the `internal` audience.
 */
#[Title('PHP API member')]
#[TypeScript]
class PhpApiMemberData extends BeamData
{
    public function __construct(
        public string $name,
        public string $visibility,
        /** @var list<PhpApiAttributeData> */
        public array $attributes,
        public int $line,
        /** @var list<string> */
        public array $doc,
        /** @var list<PhpApiTagData> */
        public array $tags,
        public Optional|bool $static = new Optional,
        public Optional|bool $readonly = new Optional,
        public Optional|bool $abstract = new Optional,
        public Optional|bool $final = new Optional,
        public Optional|bool $byRef = new Optional,
        public Optional|bool $promoted = new Optional,
        public Optional|string|null $type = new Optional,
        public Optional|string|null $value = new Optional,
        public Optional|string|null $default = new Optional,
        /** @var Optional|list<PhpApiParamData> */
        public Optional|array $params = new Optional,
        public Optional|string|null $returnType = new Optional,
        /** @var Optional|list<PhpApiTemplateData> */
        public Optional|array $templates = new Optional,
        public Optional|string $signature = new Optional,
        public Optional|string $comment = new Optional,
    ) {}
}
