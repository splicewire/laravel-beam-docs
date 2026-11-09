<?php

namespace Fixture\ApiA\Concerns;

/**
 * Gives a class a display name.
 *
 * @api
 */
trait HasName
{
    protected string $name = 'unnamed';

    /** The display name. */
    public function name(): string
    {
        return $this->name;
    }
}
