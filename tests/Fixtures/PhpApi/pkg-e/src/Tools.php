<?php

namespace Fixture\ApiE;

/**
 * Tools a kit composes.
 *
 * @api
 */
trait Tools
{
    /**
     * Does it quietly (ticket 7).
     *
     * @param  int  $times  How often.
     */
    #[Marker('quietly')]
    private function secretly(int $times = 2, ?string $label = null): void {}

    /** Shows the value. */
    public function visible(string $value): string
    {
        return $value;
    }

    /** Raises the voice. */
    public function shout(): void {}

    /** @internal */
    public function hush(): void {}
}
