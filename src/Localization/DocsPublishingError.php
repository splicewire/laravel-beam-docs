<?php

namespace Splicewire\Beam\Docs\Localization;

use Splicewire\Beam\Localization\Attributes\Message;
use Splicewire\Beam\Localization\CodedError;
use Splicewire\Beam\Localization\Concerns\IsCodedError;

enum DocsPublishingError: string implements CodedError
{
    use IsCodedError;

    #[Message('Configure a valid Scalar :destination before publishing.')]
    case InvalidDestination = 'docs_publish.invalid_destination';

    #[Message('Generate the configured public-reference OpenAPI artifact before publishing.')]
    case MissingArtifact = 'docs_publish.missing_artifact';

    #[Message('The OpenAPI artifact exceeds the 20 MiB publication limit.')]
    case ArtifactTooLarge = 'docs_publish.artifact_too_large';

    #[Message('Only failed publication attempts can be retried.')]
    case RetryRequiresFailure = 'docs_publish.retry_requires_failure';

    #[Message('Documentation publishing is only available in the host context.')]
    case HostRequired = 'docs_publish.host_required';

    #[Message('Documentation is disabled.')]
    case Disabled = 'docs_publish.disabled';

    #[Message('Scalar publishing is not enabled.')]
    case ScalarDisabled = 'docs_publish.scalar_disabled';

    #[Message('Documentation visibility must be public or private.')]
    case InvalidVisibility = 'docs_publish.invalid_visibility';

    #[Message('This public attempt cannot run while documentation is private. Create a new private attempt.')]
    case VisibilityChanged = 'docs_publish.visibility_changed';

    #[Message('The configured Scalar destination has changed. Restore it to retry this snapshot.')]
    case DestinationChanged = 'docs_publish.destination_changed';
}
