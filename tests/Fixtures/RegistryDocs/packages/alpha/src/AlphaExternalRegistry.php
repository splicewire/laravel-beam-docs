<?php

namespace Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Alpha;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryKey;

/** Census fixture: a root the manifest satisfies with an EXTERNAL URL. */
#[IsRegistry(root: 'fixture.alpha.external', description: 'a fixture registry documented elsewhere')]
class AlphaExternalRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'fixture.alpha.external';
    }

    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        return (string) $index;
    }
}
