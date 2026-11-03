<?php

namespace Splicewire\Beam\Docs\Editor\Nodes;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/**
 * A Code Component from `@splicewire/beam-docs`: the registrant panel of a registry page. It reads
 * `GET beam/docs/registries/{root}/registrants` live, because who registers into a root depends on what the
 * serving host installs, so nothing of it is written into the page. Declared here, where it renders, so a
 * registry page's MDX can place it (the scaffold stub does).
 */
#[NodeType('RegistryRegistrants', description: 'Who owns a registry and who registers into it, on this site.')]
final class RegistryRegistrants extends Block
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
                'root' => ['type' => 'string', 'title' => 'Registry root'],
            ],
            'required' => ['root'],
        ];
    }
}
