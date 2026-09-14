<?php

namespace Splicewire\Beam\Docs\Publishing\Data;

use Splicewire\Beam\Data\BeamData;
use Splicewire\Beam\Docs\Publishing\Publication;

class PublicationData extends BeamData
{
    public function __construct(
        public string $id,
        public string $version,
        public string $status,
        public string $sha256,
        public string $namespace,
        public string $slug,
        public bool $isPrivate,
        public ?string $registryUrl,
        public ?string $error,
        public ?string $createdAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $retryOf,
    ) {}

    public static function fromPublication(Publication $publication): static
    {
        return new static(
            $publication->id,
            $publication->version,
            $publication->status,
            $publication->sha256,
            $publication->namespace,
            $publication->slug,
            $publication->is_private,
            $publication->status === 'succeeded' ? $publication->registry_url : null,
            $publication->error,
            $publication->created_at?->toIso8601String(),
            $publication->started_at?->toIso8601String(),
            $publication->finished_at?->toIso8601String(),
            $publication->retry_of,
        );
    }
}
