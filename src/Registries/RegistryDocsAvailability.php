<?php

namespace Splicewire\Beam\Docs\Registries;

use Rushing\Popcorn\Registries\RegistryIndex;
use Throwable;

/**
 * Whether this host's RUNTIME registry index describes a root — the render-time availability
 * annotation's instrument (registry-docs ticket 01, amended rulings 2 and 7): a catalog row whose
 * root this host does not compose renders "not available on this host" — SHOWN, not hidden.
 *
 * Three-valued on purpose: `null` means the index could not answer (unbaked, unbound, mid-boot),
 * and a consumer must never render `null` as absence — the annotation is only honest on an explicit
 * `false`. The empty root is structurally skipped (never a legal key, never annotated).
 */
class RegistryDocsAvailability
{
    public function knownLocally(string $root): ?bool
    {
        if ($root === '') {
            return null;
        }

        try {
            return app(RegistryIndex::class)->declarationAt($root) !== null;
        } catch (Throwable) {
            return null;
        }
    }
}
