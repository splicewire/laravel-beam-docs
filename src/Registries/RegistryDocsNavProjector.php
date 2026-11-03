<?php

namespace Splicewire\Beam\Docs\Registries;

use Illuminate\Contracts\Auth\Authenticatable;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Preview\PreviewContext;

/**
 * beam-ux's {@see NavProjector}, decorated with the registry reference's catalog composition
 * ({@see RegistryDocsNav}, ticket 01 ruling 7). The inner projector does all of the projecting and
 * all of the gating; this only regroups the `{segment}/registries` subtree of its answer. The
 * parent constructor is deliberately not called: every public method delegates to `$inner`.
 */
class RegistryDocsNavProjector extends NavProjector
{
    public function __construct(
        private NavProjector $inner,
        private RegistryDocsNav $nav,
    ) {}

    public function project(string $realm, ?Authenticatable $actor = null, ?PreviewContext $preview = null): NavTree
    {
        return $this->nav->compose($this->inner->project($realm, $actor, $preview));
    }
}
