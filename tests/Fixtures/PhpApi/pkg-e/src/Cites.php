<?php

namespace Fixture\ApiE;

/**
 * Exposes a private trait method under a public alias.
 *
 * @api
 */
class Cites
{
    use Citing {
        privateCite as public cited;
        internalCite as public alsoInternal;
    }
}
