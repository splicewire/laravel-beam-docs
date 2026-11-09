<?php

namespace Fixture\ApiE;

use Fixture\ApiF\Remote;

/**
 * Uses a trait from ANOTHER package, aliasing its private method public.
 *
 * @api
 */
class Bridge
{
    use Remote {
        farAwayMethod as public near;
    }
}
