<?php

namespace Splicewire\Beam\Docs\Registries;

use Rushing\Popcorn\Registries\RecordsRegistrants;
use Rushing\Popcorn\Registries\Registry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Docs\Data\RegistryRegistrantData;
use Splicewire\Beam\Docs\Data\RegistryRegistrantsData;
use Throwable;

/**
 * Reads the registrant panel for one root off THIS host's runtime index (registry-docs ticket 03;
 * ticket 01 amended ruling 6). See {@see RegistryRegistrantsData} for the two tiers and the null.
 *
 * Every read goes through the registry's own filtered surface — `keys()` and `registrantOf()` —
 * never `unfiltered()`: a hidden entry must answer exactly like an absent one. The empty root is
 * structurally skipped (ruling 3); a root this host does not describe answers the
 * "not available on this host" line rather than an error.
 */
class RegistryRegistrants
{
    public const NOT_RECORDED = 'This registry does not record who registered each entry.';

    public const NOT_HERE = 'not available on this host';

    public function for(string $root): RegistryRegistrantsData
    {
        $describedHere = app(RegistryDocsAvailability::class)->knownLocally($root);

        if ($describedHere !== true) {
            return new RegistryRegistrantsData($root, $describedHere, null, null, false, null, null, $describedHere === false ? self::NOT_HERE : null);
        }

        try {
            $index = app(RegistryIndex::class);
            $owner = $index->owner($root);
            $describedBy = $index->registrantOf($root);
        } catch (Throwable) {
            return new RegistryRegistrantsData($root, null, null, null, false, null, null, null);
        }

        $class = is_object($owner) ? $owner::class : null;

        if (! $owner instanceof RecordsRegistrants || ! $owner instanceof Registry) {
            return new RegistryRegistrantsData($root, true, $class, $describedBy, false, null, null, self::NOT_RECORDED);
        }

        $entries = [];
        $unattributed = 0;

        foreach ($owner->keys() as $key) {
            $registrant = $owner->registrantOf($key);

            if ($registrant === null || $registrant === '') {
                $unattributed++;

                continue;
            }

            $entries[] = new RegistryRegistrantData((string) $key, $registrant);
        }

        return new RegistryRegistrantsData($root, true, $class, $describedBy, true, $entries, $unattributed, null);
    }
}
