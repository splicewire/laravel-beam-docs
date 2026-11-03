<?php

namespace Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Alpha;

use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryKey;

/** Census fixture: a root the manifest EXEMPTS with a reason and a pointer. */
#[IsRegistry(root: 'fixture.alpha.exempt', description: 'a rebind-only fixture registry')]
class AlphaExemptRegistry extends ConfigRegistry
{
    protected function configKey(): string
    {
        return 'fixture.alpha.exempt';
    }

    protected function keyFor(int|string $index, mixed $entry): RegistryKey|string
    {
        return (string) $index;
    }
}
