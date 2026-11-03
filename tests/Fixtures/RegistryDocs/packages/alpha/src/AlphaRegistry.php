<?php

namespace Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Alpha;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryKey;

/** Census fixture: a root covered by the CONVENTIONAL page `docs/registries/fixture.alpha.mdx`. */
#[IsRegistry(root: 'fixture.alpha', description: 'a fixture registry with a conventional page')]
class AlphaRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'fixture.alpha';
    }

    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        return (string) $index;
    }
}
