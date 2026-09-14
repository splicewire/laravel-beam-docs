<?php

namespace Splicewire\Beam\Docs\Publishing;

class PublicationPolicy
{
    public function assertHostContext(): void
    {
        // A host publishes its build artifact, never a tenant-selected artifact or tenant row.
        if (app()->bound('tenancy') && app('tenancy')->initialized) {
            throw new PublicationFailure('Documentation publishing is only available in the host context.');
        }
    }

    public function assertEnabled(): void
    {
        $this->assertHostContext();

        if (! config('beam.docs.enabled', true)) {
            throw new PublicationFailure('Documentation is disabled.');
        }

        if (! config('beam.docs.scalar.enabled', false)) {
            throw new PublicationFailure('Scalar publishing is not enabled.');
        }

        if (! in_array(config('beam.docs.visibility', 'public'), ['public', 'private'], true)) {
            throw new PublicationFailure('Documentation visibility must be public or private.');
        }
    }

    public function assertMayUpload(Publication $publication): void
    {
        $this->assertEnabled();

        if (! $publication->is_private && config('beam.docs.visibility', 'public') !== 'public') {
            throw new PublicationFailure('This public attempt cannot run while documentation is private. Create a new private attempt.');
        }

        // Retrying never silently switches destinations after configuration changes.
        if ($publication->namespace !== config('beam.docs.scalar.namespace') || $publication->slug !== config('beam.docs.scalar.slug')) {
            throw new PublicationFailure('The configured Scalar destination has changed. Restore it to retry this snapshot.');
        }
    }
}
