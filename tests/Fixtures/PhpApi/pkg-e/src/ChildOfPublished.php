<?php

namespace Fixture\ApiE;

/**
 * Uses a published trait that requires foo; the parent implements it.
 *
 * @api
 */
class ChildOfPublished extends Parent_
{
    use RequiresPublished;
}
