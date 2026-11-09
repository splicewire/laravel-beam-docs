<?php

namespace Fixture\ApiE;

/**
 * Requires foo; implements bar.
 *
 * @api
 */
trait RequiresPublished
{
    abstract public function foo(): string;

    /** The trait's bar. */
    public function bar(): string
    {
        return 'trait';
    }
}
