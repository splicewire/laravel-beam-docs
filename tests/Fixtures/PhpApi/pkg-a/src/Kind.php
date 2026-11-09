<?php

namespace Fixture\ApiA;

/**
 * The kinds of box.
 *
 * @api
 */
enum Kind: string
{
    /** A round one. */
    case Circle = 'circle';
    case Square = 'square';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
