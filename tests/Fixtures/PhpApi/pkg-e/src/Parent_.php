<?php

namespace Fixture\ApiE;

/**
 * A concrete parent.
 *
 * @api
 */
class Parent_
{
    /** The parent's foo. */
    public function foo(): string
    {
        return 'parent';
    }

    /** The parent's bar. */
    public function bar(): string
    {
        return 'parent';
    }
}
