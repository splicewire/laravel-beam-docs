<?php

namespace Splicewire\Beam\Docs\Reconcile;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Release\ReleaseService;

/**
 * Take one row down through a Release, the path every manual take-down used (`beam.notifications.recipient_kinds`,
 * the CS-11 service-desk page): create, `putMember(ref: null, redirectTarget)`, then publish, or submit where the host
 * requires review. The Release authorizes the actor, keeps the row and its history, and leaves the redirect.
 */
class ReleaseTakeDown
{
    public function __construct(private ReleaseService $releases) {}

    /** @return array{0: string, 1: string} the Release id and its status after the transition */
    public function takeDown(BeamUxEntry $entry, ?BeamUxEntry $target, string $title, Authenticatable $actor): array
    {
        $release = $this->releases->create($entry, $title, $actor);
        $this->releases->putMember($release, (string) $entry->id, null, $actor, $target?->id === null ? null : (string) $target->id);
        $this->releases->transition($release, config('beam.ux.releases.review_required', false) ? 'submit' : 'publish', $actor);
        $release->refresh();

        return [(string) $release->id, (string) $release->status];
    }
}
