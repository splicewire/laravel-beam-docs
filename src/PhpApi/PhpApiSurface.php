<?php

namespace Splicewire\Beam\Docs\PhpApi;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The seeded PHP API page as the read ops' authorization (php-api-reference slice 2): a principal may read the data only
 * if they could open that page, through the same gate a page view takes (`PublicEntryGate::chainForEntry()`: realm,
 * ancestry, publish state, render access and every requirement on the chain, `beam-docs` and `beam-docs-php-api`
 * included). So the data routes cannot outlive the surface: no seeded row, an unpublished one, private docs for a guest
 * or an unavailable artifact are all the same 404.
 */
final class PhpApiSurface
{
    public function __construct(private PublicEntryGate $gate) {}

    public function readable(?Authenticatable $actor): bool
    {
        $entry = BeamUxEntry::query()->where('slug', DocsSeeder::PHP_API_SLUG)->whereNull('namespace')->first();

        return $entry !== null && $this->gate->chainForEntry($entry, BeamUxEntry::REALM_SITE, $actor) !== null;
    }
}
