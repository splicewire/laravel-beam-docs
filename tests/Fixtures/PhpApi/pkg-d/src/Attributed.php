<?php

namespace Fixture\ApiD;

/** @api */
class Attributed
{
    #[Marker('constant')]
    public const LIMIT = 3;

    #[Marker('property')]
    public string $label = '';
}
