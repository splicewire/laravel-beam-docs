<?php

namespace Fixture\ApiD\Traits;

/** @api */
trait Waves
{
    use Nested;

    public function hello(): string
    {
        return 'wave';
    }
}
