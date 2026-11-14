<?php

namespace Splicewire\Beam\Docs\Publishing;

use Splicewire\Beam\Docs\Localization\CodedPublicationFailure;
use Splicewire\Beam\Docs\Localization\DocsPublishingError;

class PublicationPolicy
{
    public function assertHostContext(): void
    {
        // A host publishes its build artifact, never a tenant-selected artifact or tenant row.
        if (app()->bound('tenancy') && app('tenancy')->initialized) {
            throw CodedPublicationFailure::for(DocsPublishingError::HostRequired);
        }
    }

    public function assertEnabled(): void
    {
        $this->assertHostContext();

        if (! config('beam.docs.enabled', true)) {
            throw CodedPublicationFailure::for(DocsPublishingError::Disabled);
        }

        if (! config('beam.docs.scalar.enabled', false)) {
            throw CodedPublicationFailure::for(DocsPublishingError::ScalarDisabled);
        }

        if (! in_array(config('beam.docs.visibility', 'public'), ['public', 'private'], true)) {
            throw CodedPublicationFailure::for(DocsPublishingError::InvalidVisibility);
        }
    }

    public function assertMayUpload(Publication $publication): void
    {
        $this->assertEnabled();

        if (! $publication->is_private && config('beam.docs.visibility', 'public') !== 'public') {
            throw CodedPublicationFailure::for(DocsPublishingError::VisibilityChanged);
        }

        // Retrying never silently switches destinations after configuration changes.
        if ($publication->namespace !== config('beam.docs.scalar.namespace') || $publication->slug !== config('beam.docs.scalar.slug')) {
            throw CodedPublicationFailure::for(DocsPublishingError::DestinationChanged);
        }
    }
}
