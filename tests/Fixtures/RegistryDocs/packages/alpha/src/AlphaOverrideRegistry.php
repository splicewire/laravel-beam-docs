<?php

namespace Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Alpha;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryKey;

/** Census fixture: a root the manifest points at a NON-default page path (override). */
#[IsRegistry(root: 'fixture.alpha.override', description: 'a fixture registry with a relocated page')]
class AlphaOverrideRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'fixture.alpha.override';
    }

    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        return (string) $index;
    }
}
