<?php

namespace Splicewire\Beam\Docs\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Docs\PhpApi\PhpApiArtifact;
use Splicewire\Beam\Ux\Access\EntryRequirement;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `beam-docs-php-api` (php-api-reference slice 2): the PHP API reference exists for this principal only when docs are
 * readable to them AND the generated artifact is available (a non-empty package list, a valid artifact for the configured
 * audience and inputs, at least one published symbol; {@see PhpApiArtifact}). Every consumer of the seeded entry
 * (page, header, nav, search, sitemap) and both read ops resolve through this one answer, so turning the list on or off
 * needs no reseed and nothing can show a surface the others hide.
 */
class PhpApiEntryRequirement implements EntryRequirement
{
    public const NAME = 'beam-docs-php-api';

    public function __construct(private DocsAccess $access, private PhpApiArtifact $artifact) {}

    public function allows(?Authenticatable $actor, BeamUxEntry $entry): bool
    {
        return $this->access->allows($actor) && $this->artifact->available();
    }
}
