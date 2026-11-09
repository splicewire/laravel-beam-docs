<?php

namespace Fixture\ApiA;

/**
 * References classes this install does not have.
 *
 * @api
 */
class UsesMissing extends \Vendor\Uninstalled\Base implements \Missing\Contract
{
    public function run(\Vendor\Uninstalled\Thing $thing): void {}
}
