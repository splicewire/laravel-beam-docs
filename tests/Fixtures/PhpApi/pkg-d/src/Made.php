<?php

namespace Fixture\ApiD;

/** @api */
final class Made
{
    private function __construct(#[Marker('promoted')] public string $name, private int $count = 0) {}

    public static function of(string $name): self
    {
        return new self($name);
    }
}
