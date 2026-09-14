<?php

namespace Splicewire\Beam\Docs\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Access\EntryRequirement;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class DocsEntryRequirement implements EntryRequirement
{
    public function __construct(private DocsAccess $access) {}

    public function allows(?Authenticatable $actor, BeamUxEntry $entry): bool
    {
        return $this->access->allows($actor);
    }
}
