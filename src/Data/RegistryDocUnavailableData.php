<?php

namespace Splicewire\Beam\Docs\Data;

use Splicewire\Beam\Data\BeamData;

/**
 * The deep-link route's answer for a catalogued root this host cannot link (registry-docs ticket 01,
 * amended rulings 2 and 7): the row is SHOWN, never hidden — a 200 carrying the root, what the
 * catalog knows about it, and the render-time availability annotation. `describedHere` is
 * three-valued: null means the runtime index could not answer, and only an explicit false earns
 * the "not available on this host" note.
 */
class RegistryDocUnavailableData extends BeamData
{
    public function __construct(
        public string $root,
        public bool $available,
        public ?bool $describedHere,
        public ?string $note,
        /** The catalog row's doc kind — page | external | exempt — or null for an uncovered root. */
        public ?string $kind,
        public ?string $reason,
        public ?string $pointer,
    ) {}
}
