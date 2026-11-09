<?php

namespace Fixture\ApiA;

use Fixture\ApiA\Concerns\HasName;
use Fixture\ApiA\Contracts\Shape;
use Illuminate\Support\Collection;

/**
 * A box that holds one value (launch ticket 23).
 *
 * APP-09b: boxes are immutable once made.
 *
 * Read {@see Kind} for the kinds; ruling T23b3 explains why the side is fixed.
 *
 * A plain paragraph that cites nothing stays whole.
 *
 * @api
 *
 * @template T of object
 */
#[\AllowDynamicProperties]
class Box implements Shape
{
    use HasName;

    /** The default side (ADR-0011). */
    public const DEFAULT_SIDE = 2.0;

    private const SECRET = 'x';

    /** @param T $value */
    public function __construct(public readonly object $value, protected float $side = self::DEFAULT_SIDE, private int $hidden = 0) {}

    public function area(): float
    {
        return $this->side ** 2;
    }

    /**
     * Map the value through a callback.
     *
     * @template U
     *
     * @param  callable(T): U  $fn
     */
    public function map(callable $fn, int &...$extra): static|Collection|null
    {
        return null;
    }

    /** @internal */
    public function rawSide(): float
    {
        return $this->side;
    }

    private function secret(): void {}

    public static function make(?object $value = null, array $options = ['a' => 1]): self
    {
        return new self($value ?? new \stdClass);
    }
}
