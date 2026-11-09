<?php

namespace Fixture\ApiD;

/**
 * A typed holder.
 *
 * @api
 *
 * @template-covariant T of object = \stdClass
 *
 * @extends Base
 *
 * @implements \IteratorAggregate<int, T>
 */
class Typed extends Base implements \IteratorAggregate
{
    /**
     * Hold the items.
     *
     * @param  list<T>  $items  The items to hold (ticket 12).
     * @return T The first one.
     *
     * @throws \RuntimeException When there are none.
     */
    public function first(array $items): object
    {
        return $items[0];
    }

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator([]);
    }
}
