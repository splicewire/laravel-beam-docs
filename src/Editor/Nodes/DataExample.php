<?php

namespace Splicewire\Beam\Docs\Editor\Nodes;

use Schemastud\Blockdoc\Attributes\NodeType;
use Schemastud\Blockdoc\Blocks\Block;

/**
 * A Code Component from `@splicewire/beam-docs`: a registry page's data example, the PHP that ran beside the JSON it
 * returned (docs-feedback ticket 09). Its children are the two fenced code blocks, and both are generated: the
 * package's `docs:examples` script captures the output and writes the block between its `data-example` markers
 * (`rushing/php-popcorn`'s `DataExamples`), and the package's suite checks it. `source` is the example's
 * package-relative path, shown as the PHP pane's label.
 */
#[NodeType('DataExample', description: 'The PHP that ran beside the JSON it returned: a captured data example on a registry page.')]
final class DataExample extends Block
{
    public function admitsText(): bool
    {
        return true;
    }

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
                'source' => ['type' => 'string', 'title' => 'Example source (package-relative)'],
            ],
        ];
    }
}
