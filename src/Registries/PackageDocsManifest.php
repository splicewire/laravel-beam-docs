<?php

namespace Splicewire\Beam\Docs\Registries;

use Rushing\Popcorn\Registries\Docs\RegistryDocsManifest;

/**
 * One package's committed docs manifest, `docs/registries/manifest.json` (registry-docs ticket 01,
 * amended rulings 3-5).
 *
 * **The parser moved down to `rushing/php-popcorn`** ({@see RegistryDocsManifest}, registry-docs
 * ticket 04). The package-tier check has to read this file without Laravel, and a second copy of the
 * parser here would let the two tiers disagree about which entries are valid. This name stays as a
 * subclass so the catalog command, the source contributor and any in-flight branch that names it keep
 * working. New code should use the popcorn class.
 *
 * Ticket 04 also made an exemption's `class` (infrastructure | rebind-only | app-local) a mandatory
 * field, and the pointer mandatory for the first two. See the popcorn class for the full shape.
 */
class PackageDocsManifest extends RegistryDocsManifest {}
