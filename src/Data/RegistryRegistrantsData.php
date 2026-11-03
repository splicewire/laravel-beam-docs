<?php

namespace Splicewire\Beam\Docs\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Splicewire\Beam\Data\BeamData;

/**
 * The registrant panel of a registry page (registry-docs ticket 03; ticket 01 amended ruling 6).
 * Registrants are HOST-VARIANT — which packages register into a root depends on what this host
 * installs — so the serving site answers this live and nothing of it is ever committed to a page.
 *
 * Two tiers, deliberately separate:
 *
 * - **Always** (where this host describes the root): the registry's `owner` (the class described at
 *   the root) and `describedBy` — the index's membership provenance, `RegistryIndex::registrantOf()`,
 *   total for described registries. That is WHO DESCRIBED THE REGISTRY, not who registered entries.
 * - **Only where the registry implements `RecordsRegistrants`**: `entries`, each visible entry that
 *   names its registrant. Otherwise `entries` is null and `note` says so in one line. Entries whose
 *   registration named nobody are counted in `unattributed`, never listed with an invented name.
 *
 * Every read is FILTERED through the host's authorizer: a hidden entry is byte-identical to an
 * absent one here, exactly as `has()` answers it (RecordsRegistrants.php — an unfiltered registrant
 * read is an existence oracle).
 */
class RegistryRegistrantsData extends BeamData
{
    /** @param  list<RegistryRegistrantData>|null  $entries */
    public function __construct(
        public string $root,
        /** Three-valued: null = this host's index could not answer; false = not composed here. */
        public ?bool $describedHere,
        /** The class described at the root, where this host describes it. */
        public ?string $owner,
        /** Who described the registry into this host's index (index-level membership provenance). */
        public ?string $describedBy,
        /** Whether the registry can say who registered each entry. */
        public bool $recordsRegistrants,
        /** @var list<RegistryRegistrantData>|null */
        #[DataCollectionOf(RegistryRegistrantData::class)]
        public ?array $entries,
        /** Visible entries whose registration named no registrant (null where entries is null). */
        public ?int $unattributed,
        /** The one line shown in place of the per-entry list, when there is none. */
        public ?string $note,
    ) {}
}
