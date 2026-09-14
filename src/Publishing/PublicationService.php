<?php

namespace Splicewire\Beam\Docs\Publishing;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Splicewire\Beam\Docs\Publishing\Data\PublishInputData;
use Splicewire\Beam\Docs\Publishing\Jobs\PublishToScalar;
use Splicewire\Beam\OpenApi\OpenApiSpecSource;
use Splicewire\Beam\OpenApi\SpecFormat;
use Throwable;

class PublicationService
{
    public function __construct(
        private OpenApiSpecSource $source,
        private PublicationPolicy $policy,
        private ScalarCli $scalar,
        private Dispatcher $dispatcher,
    ) {}

    public function capture(string $version): Publication
    {
        $this->policy->assertEnabled();
        PublishInputData::validate(['version' => $version]);
        $namespace = config('beam.docs.scalar.namespace');
        $slug = config('beam.docs.scalar.slug');
        foreach (['namespace' => $namespace, 'slug' => $slug] as $key => $value) {
            if (! is_string($value) || strlen($value) > 128 || ! preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $value)) {
                throw new PublicationFailure('Configure a valid Scalar '.$key.' before publishing.');
            }
        }

        // The request is deliberately synthetic: an operator cannot select a tenant/audience,
        // artifact path, destination, visibility, credential or alternate Scribe configuration.
        $spec = $this->source->spec(SpecFormat::Yaml, Request::create('/'));
        if ($spec === null || trim($spec->body) === '') {
            throw new PublicationFailure('Generate the configured public-reference OpenAPI artifact before publishing.');
        }
        if (strlen($spec->body) > 20 * 1024 * 1024) {
            throw new PublicationFailure('The OpenAPI artifact exceeds the 20 MiB publication limit.');
        }

        return Publication::query()->create([
            'id' => (string) Str::uuid(),
            'version' => $version,
            'namespace' => $namespace,
            'slug' => $slug,
            'is_private' => config('beam.docs.visibility', 'public') === 'private',
            'snapshot' => $spec->body,
            'sha256' => hash('sha256', $spec->body),
            'status' => 'queued',
        ]);
    }

    public function retry(string $id): Publication
    {
        $this->policy->assertHostContext();
        $original = $this->find($id);
        $this->policy->assertMayUpload($original);
        if ($original->status !== 'failed') {
            throw new PublicationFailure('Only failed publication attempts can be retried.');
        }

        // A unique retry_of makes repeated clicks and concurrent retry requests idempotent.
        return Publication::query()->firstOrCreate(['retry_of' => $original->id], [
            'id' => (string) Str::uuid(),
            'version' => $original->version,
            'namespace' => $original->namespace,
            'slug' => $original->slug,
            'is_private' => $original->is_private,
            'snapshot' => $original->snapshot,
            'sha256' => $original->sha256,
            'status' => 'queued',
        ]);
    }

    public function enqueue(Publication $publication): Publication
    {
        $this->policy->assertHostContext();
        try {
            $job = new PublishToScalar($publication->id);
            $job->onQueue((string) config('beam.docs.scalar.queue', 'default'));
            $this->dispatcher->dispatch($job);
        } catch (Throwable) {
            $this->fail($publication->id, 'The publishing job could not be queued. Check the worker queue configuration and retry.');
        }

        return $publication->fresh();
    }

    public function run(string $id): Publication
    {
        $this->policy->assertHostContext();
        // One atomic transition claims an attempt, including competing workers/duplicate jobs.
        $claimed = Publication::query()->whereKey($id)->where('status', 'queued')->update([
            'status' => 'running', 'started_at' => now(), 'updated_at' => now(),
        ]);
        $publication = $this->find($id);
        if (! $claimed) {
            return $publication;
        }

        try {
            $this->policy->assertMayUpload($publication);
            if (! hash_equals($publication->sha256, hash('sha256', $publication->snapshot))) {
                throw new PublicationFailure('The captured OpenAPI artifact failed its integrity check.');
            }
            $url = $this->scalar->publish($publication, fn () => $this->policy->assertMayUpload($publication));
            Publication::query()->whereKey($id)->where('status', 'running')->update([
                'status' => 'succeeded', 'registry_url' => $url, 'finished_at' => now(), 'error' => null, 'updated_at' => now(),
            ]);
        } catch (Throwable $error) {
            $message = $error instanceof PublicationFailure ? $error->getMessage() : 'The publishing worker failed. Check worker configuration and connectivity before retrying.';
            $this->fail($id, $message);
        }

        return $this->find($id);
    }

    public function fail(string $id, string $message): void
    {
        $this->policy->assertHostContext();
        Publication::query()->whereKey($id)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'error' => mb_substr($message, 0, 1000), 'finished_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function find(string $id): Publication
    {
        $this->policy->assertHostContext();

        return Publication::query()->findOrFail($id);
    }

    /** @return Collection<int, Publication> */
    public function recent(): Collection
    {
        $this->policy->assertHostContext();

        return Publication::query()->orderByDesc('created_at')->orderByDesc('id')->limit(20)->get();
    }

    public function latestSuccessfulUrl(): ?string
    {
        if (! config('beam.docs.enabled', true) || ! config('beam.docs.scalar.show_link', false)) {
            return null;
        }
        $this->policy->assertHostContext();
        $publication = Publication::query()->where('status', 'succeeded')
            ->where('namespace', config('beam.docs.scalar.namespace'))
            ->where('slug', config('beam.docs.scalar.slug'))
            ->where('is_private', config('beam.docs.visibility', 'public') === 'private')
            ->orderByDesc('finished_at')->orderByDesc('id')->first();

        return $publication !== null && $publication->registry_url === ScalarCli::registryUrl($publication)
            ? $publication->registry_url : null;
    }
}
