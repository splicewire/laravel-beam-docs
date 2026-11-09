<?php

namespace Splicewire\Beam\Docs\Editor\Nodes;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/**
 * A Code Component from `@splicewire/beam-docs` (php-api-reference slice 3): the browsable PHP API reference, read
 * from this package's `beam-docs.php-api-index` / `php-api-symbol` ops. The seeded `docs-php-api` page places it with
 * no attributes (the component reads the configured endpoint); declared here, where it renders, so an MDX page holding
 * blocks can place it, as {@see ApiReference} is.
 */
#[NodeType('PhpApiReference', description: 'The PHP API reference for the packages this site documents.')]
final class PhpApiReference extends Block
{
    public function category(): ?string
    {
        return 'html';
    }

    public function admitsChildCategories(): ?array
    {
        return null;
    }

    /** @return array<string, mixed> */
    public static function attrsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass,
            'required' => [],
            'additionalProperties' => false,
        ];
    }
}
