<?php

namespace Fixture\ApiG;

use Attribute;

/**
 * An attribute with no arguments.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Plain {}
