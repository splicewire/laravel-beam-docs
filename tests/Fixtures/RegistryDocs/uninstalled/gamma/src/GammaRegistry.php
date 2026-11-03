<?php

namespace Splicewire\Beam\Docs\Tests\Fixtures\Uninstalled\Gamma;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryKey;

/** Census fixture for a package the host does NOT install: reachable only through its composer.json psr-4 map. */
#[IsRegistry(root: 'fixture.gamma', description: 'a fixture registry in a package this host does not install')]
class GammaRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'fixture.gamma';
    }

    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        return (string) $index;
    }
}
