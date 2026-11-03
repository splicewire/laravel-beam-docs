<?php

namespace Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Beta;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryKey;

/** Census fixture: a declared root with NO page, manifest entry or exemption — an uncovered row. */
#[IsRegistry(root: 'fixture.beta', description: 'a fixture registry nobody documented yet')]
class BetaRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'fixture.beta';
    }

    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        return (string) $index;
    }
}
