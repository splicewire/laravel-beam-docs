<?php

namespace Fixture\ApiE;

#[\Attribute]
final class Marker
{
    public function __construct(public string $where) {}
}
