<?php

namespace Fixture\ApiD;

#[\Attribute]
final class Marker
{
    public function __construct(public string $where) {}
}
