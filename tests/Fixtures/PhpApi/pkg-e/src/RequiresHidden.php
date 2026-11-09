<?php

namespace Fixture\ApiE;

/** Unmarked: requires foo. */
trait RequiresHidden
{
    abstract public function foo(): string;
}
