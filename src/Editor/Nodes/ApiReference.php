<?php

namespace Splicewire\Beam\Docs\Editor\Nodes;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/**
 * A Code Component from `@splicewire/beam-docs`: an API's reference, read from its OpenAPI spec. This package's docs
 * page places it; declared here, where it renders, so an MDX page holding blocks can place it (EP-42 S1).
 */
#[NodeType('ApiReference', description: 'An API reference, read from its OpenAPI spec.')]
final class ApiReference extends Block
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
            'properties' => [
                'specUrl' => ['type' => 'string', 'title' => 'OpenAPI spec URL'],
            ],
            'required' => ['specUrl'],
        ];
    }
}
