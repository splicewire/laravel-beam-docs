<?php

namespace Fixture\ApiD\Traits;

/** @api */
trait Greets
{
    public function hello(): string
    {
        return 'hi';
    }

    public function bye(): void {}

    private function secretly(): void {}
}
