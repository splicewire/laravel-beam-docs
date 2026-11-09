<?php

namespace Fixture\ApiE;

/**
 * A kit whose trait adaptations change names and visibility.
 *
 * @api
 */
class Kit
{
    use Tools {
        secretly as public revealed;
        visible as protected;
        shout as protected loud;
        hush as public quiet;
    }
}
