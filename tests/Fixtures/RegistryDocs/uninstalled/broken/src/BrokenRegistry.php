<?php

namespace Splicewire\Beam\Docs\Tests\Fixtures\Uninstalled\Broken;

use Rushing\Popcorn\Registries\IsRegistry;
use Splicewire\NotInstalled\SomeBaseRegistry;

/** Census fixture: its parent is in no installed package, so it cannot load in this process. */
#[IsRegistry(root: 'fixture.broken', description: 'a fixture registry whose parent is not installed')]
class BrokenRegistry extends SomeBaseRegistry {}
