<?php

namespace Fixture\ApiG;

use Attribute;

/**
 * A named, weighted tag.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER)]
final class Tagged
{
    public function __construct(public string $name, public int $weight = 0) {}
}
