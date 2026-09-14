<?php

namespace Splicewire\Beam\Docs\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Access\EntryAccessGate;
use Splicewire\Beam\Ux\Access\Right;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class DocsAccess
{
    public function __construct(private EntryAccessGate $gate) {}

    public function allows(?Authenticatable $actor): bool
    {
        if (! config('beam.docs.enabled', true)) {
            return false;
        }
        if (config('beam.docs.visibility', 'public') === 'public') {
            return true;
        }
        if (config('beam.docs.visibility') !== 'private') {
            return false;
        }

        return $this->gate->allows($actor, new BeamUxEntry([
            'access' => config('beam.docs.reader_tokens', ['auth']),
        ]), Right::Access);
    }
}
