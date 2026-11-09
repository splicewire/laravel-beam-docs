<?php

namespace Fixture\ApiB;

use Fixture\ApiA\Box;

/**
 * A round box, from another package.
 *
 * @api
 *
 * @extends Box<\stdClass>
 */
class Circle extends Box
{
    public function area(): float
    {
        return M_PI;
    }
}
