<?php

namespace Fixture\ApiA\Contracts;

/**
 * A shape with an area.
 *
 * @api
 */
interface Shape
{
    /** The area, in square units. */
    public function area(): float;
}
