<?php

namespace Splicewire\Beam\Docs\Publishing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Splicewire\Beam\Docs\Publishing\PublicationService;
use Throwable;

class PublishToScalar implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public string $publicationId) {}

    public function handle(PublicationService $publications): void
    {
        $publications->run($this->publicationId);
    }

    public function failed(?Throwable $exception): void
    {
        app(PublicationService::class)->fail($this->publicationId, 'The publishing worker stopped before completion. Inspect the remote version before retrying.');
    }
}
