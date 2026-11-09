<?php

namespace Fixture\ApiA;

/**
 * A fixed point.
 *
 * @api
 */
final readonly class Point
{
    public function __construct(public int $x = 0, public int $y = 0) {}
}
