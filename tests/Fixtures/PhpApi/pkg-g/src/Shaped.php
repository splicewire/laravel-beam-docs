<?php

namespace Fixture\ApiG;

/**
 * 02b fixture: a class attribute WITH arguments, and parameter attributes with and without arguments, so every declared
 * list on the symbol wire (class `attributes.*.arguments`, `member.params.*.attributes(.*.arguments)`) holds items.
 *
 * @api
 */
#[Tagged('shape', weight: 2)]
final class Shaped
{
    /** Places it. */
    public function place(#[Tagged('x')] int $x, #[Tagged('y', weight: 1)] int $y, #[Plain] ?string $label = null): void {}
}
