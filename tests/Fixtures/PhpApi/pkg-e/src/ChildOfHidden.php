<?php

namespace Fixture\ApiE;

/**
 * Uses an unmarked trait that requires foo; the parent implements it.
 *
 * @api
 */
class ChildOfHidden extends Parent_
{
    use RequiresHidden;
}
