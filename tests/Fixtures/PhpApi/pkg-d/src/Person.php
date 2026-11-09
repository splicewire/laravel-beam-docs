<?php

namespace Fixture\ApiD;

use Fixture\ApiD\Traits\Greets;
use Fixture\ApiD\Traits\Waves;

/**
 * Composes two traits with adaptations.
 *
 * @api
 */
class Person
{
    use Greets, Waves {
        Greets::hello insteadof Waves;
        Waves::hello as waveHello;
        bye as private;
        secretly as public revealed;
    }
}
