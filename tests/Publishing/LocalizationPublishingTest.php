<?php

namespace Splicewire\Beam\Docs\Tests\Publishing;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Splicewire\Beam\Docs\Localization\CodedPublicationFailure;
use Splicewire\Beam\Docs\Localization\DocsHttpException;
use Splicewire\Beam\Docs\Localization\DocsPublishingError;
use Splicewire\Beam\Docs\Localization\DocsResponses;
use Splicewire\Beam\Docs\Publishing\Publication;
use Splicewire\Beam\Docs\Publishing\PublicationFailure;
use Splicewire\Beam\Docs\Publishing\PublicationService;
use Splicewire\Beam\Docs\Publishing\ScalarCli;
use Splicewire\Beam\Localization\PseudoLocale;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LocalizationPublishingTest extends PublicationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'beam.localization.pseudo' => true, 'beam.localization.http_profile' => true, 'beam.localization.supported' => ['en', 'fr', 'de']]);
        $this->actingAs(new GenericUser(['id' => 1]));
        Gate::define('beam-docs.publish', fn ($user) => $user->id === 1);
        Bus::fake();
    }

    public function test_mounted_capture_and_retry_refusals_keep_english_status_and_mark_pseudo_copy(): void
    {
        $cases = [
            [['beam.docs.scalar.namespace' => 'invalid namespace'], 'Configure a valid Scalar namespace before publishing.', 'docs_publish.invalid_destination'],
            [['beam.docs.scalar.slug' => 'invalid slug'], 'Configure a valid Scalar slug before publishing.', 'docs_publish.invalid_destination'],
            [['beam.docs.enabled' => false], 'Documentation is disabled.', 'docs_publish.disabled'],
            [['beam.docs.scalar.enabled' => false], 'Scalar publishing is not enabled.', 'docs_publish.scalar_disabled'],
            [['beam.docs.visibility' => 'invalid'], 'Documentation visibility must be public or private.', 'docs_publish.invalid_visibility'],
        ];
        $this->assertCount(5, $cases);
        foreach (['en', 'en-XA'] as $locale) {
            app()->setLocale($locale);
            $this->withHeaders(['Accept-Language' => $locale]);
            foreach ($cases as [$config, $english, $code]) {
                config(['beam.docs.enabled' => true, 'beam.docs.scalar.enabled' => true, 'beam.docs.visibility' => 'public', 'beam.docs.scalar.namespace' => 'test-team', 'beam.docs.scalar.slug' => 'test-api']);
                config($config);
                $response = $this->postJson('/beam/docs/publications', ['version' => '1.0.0'])->assertStatus(422);
                if ($locale === 'en') {
                    $this->assertSame($english, $response->json('message'));
                } else {
                    $this->assertTrue(PseudoLocale::isMarked($response->json('message')));
                }
                $this->assertSame($code, $response->json('code'));
                $this->assertSame(['message', 'code'], array_keys($response->json()));
            }
            config(['beam.docs.enabled' => true, 'beam.docs.scalar.enabled' => true, 'beam.docs.visibility' => 'public', 'beam.docs.scalar.namespace' => 'test-team', 'beam.docs.scalar.slug' => 'test-api']);
            $attempt = $this->service()->capture('retry-1');
            $response = $this->postJson('/beam/docs/publications/'.$attempt->id.'/retry')->assertStatus(409);
            $this->assertSame('docs_publish.retry_requires_failure', $response->json('code'));
            $this->assertSame($locale === 'en', $response->json('message') === 'Only failed publication attempts can be retried.');
            if ($locale === 'en-XA') {
                $this->assertTrue(PseudoLocale::isMarked($response->json('message')));
            }
        }
        $this->assertSame([], $this->calls());
    }

    public function test_mounted_missing_and_oversized_artifact_refusals_keep_their_actual_branches(): void
    {
        foreach (['en', 'en-XA'] as $locale) {
            app()->setLocale($locale);
            $this->withHeaders(['Accept-Language' => $locale]);
            $this->source->body = null;
            $missing = $this->postJson('/beam/docs/publications', ['version' => 'missing-1'])->assertStatus(422);
            $this->assertSame('docs_publish.missing_artifact', $missing->json('code'));
            $this->source->body = str_repeat('x', 20 * 1024 * 1024 + 1);
            $large = $this->postJson('/beam/docs/publications', ['version' => 'large-1'])->assertStatus(422);
            $this->assertSame('docs_publish.artifact_too_large', $large->json('code'));
            if ($locale === 'en') {
                $this->assertSame('Generate the configured public-reference OpenAPI artifact before publishing.', $missing->json('message'));
                $this->assertSame('The OpenAPI artifact exceeds the 20 MiB publication limit.', $large->json('message'));
            } else {
                $this->assertTrue(PseudoLocale::isMarked($missing->json('message')));
                $this->assertTrue(PseudoLocale::isMarked($large->json('message')));
            }
        }
        $this->assertSame([], $this->calls());
    }

    public function test_mounted_retry_policy_refusals_preserve_snapshot_and_destination(): void
    {
        $cases = [
            [['beam.docs.visibility' => 'private'], 'This public attempt cannot run while documentation is private. Create a new private attempt.', 'docs_publish.visibility_changed'],
            [['beam.docs.scalar.slug' => 'other-api'], 'The configured Scalar destination has changed. Restore it to retry this snapshot.', 'docs_publish.destination_changed'],
        ];
        foreach (['en', 'en-XA'] as $locale) {
            app()->setLocale($locale);
            $this->withHeaders(['Accept-Language' => $locale]);
            foreach ($cases as [$change, $english, $code]) {
                config(['beam.docs.visibility' => 'public', 'beam.docs.scalar.slug' => 'test-api']);
                $attempt = $this->service()->capture('retry-policy-1');
                Publication::query()->whereKey($attempt->id)->update(['status' => 'failed']);
                $before = $attempt->fresh()->getRawOriginal();
                config($change);
                $response = $this->postJson('/beam/docs/publications/'.$attempt->id.'/retry')->assertStatus(409);
                $this->assertSame($code, $response->json('code'));
                if ($locale === 'en') {
                    $this->assertSame($english, $response->json('message'));
                } else {
                    $this->assertTrue(PseudoLocale::isMarked($response->json('message')));
                }
                $this->assertSame($before, $attempt->fresh()->getRawOriginal());
            }
        }
        $this->assertSame([], $this->calls());
    }

    public function test_success_and_the_earlier_host_gate_keep_their_existing_envelopes(): void
    {
        app()->setLocale('en-XA');
        $this->withHeaders(['Accept-Language' => 'en-XA']);
        $response = $this->postJson('/beam/docs/publications', ['version' => 'success-1'])->assertStatus(202);
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame('queued', $response->json('data.status'));
        $this->assertSame('test-team', $response->json('data.namespace'));
        $this->assertSame('test-api', $response->json('data.slug'));
        $this->assertArrayNotHasKey('snapshot', $response->json('data'));
        $this->assertArrayNotHasKey('code', $response->json('data'));
        $this->app->instance('tenancy', (object) ['initialized' => true]);
        $refused = $this->postJson('/beam/docs/publications', ['version' => 'host-1'])->assertStatus(403);
        $this->assertArrayNotHasKey('code', $refused->json());
        $this->assertSame([], $this->calls());
    }

    public function test_typed_parents_headers_json_flags_and_browser_control_match_the_existing_http_contract(): void
    {
        app()->setLocale('en');
        $previous = CodedPublicationFailure::for(DocsPublishingError::MissingArtifact);
        $exception = DocsHttpException::for(422, DocsPublishingError::MissingArtifact, previous: $previous, headers: ['X-Proof' => 'kept']);
        $this->assertInstanceOf(PublicationFailure::class, $previous);
        $this->assertInstanceOf(RuntimeException::class, $previous);
        $this->assertInstanceOf(HttpException::class, $exception);
        $this->assertSame($previous, $exception->getPrevious());
        $this->assertSame(422, $exception->getStatusCode());
        $this->assertSame(['X-Proof' => 'kept'], $exception->getHeaders());
        $this->assertNull(DocsResponses::render($exception, Request::create('/browser')));
        Route::get('/localization-docs/typed', fn () => throw $exception);
        Route::get('/localization-docs/original', fn () => throw new HttpException(422, $previous->getMessage(), null, ['X-Proof' => 'kept']));
        $typed = $this->getJson('/localization-docs/typed')->assertStatus(422);
        $original = $this->getJson('/localization-docs/original')->assertStatus(422);
        $body = $typed->json();
        unset($body['code']);
        $this->assertSame($original->json(), $body);
        $originalHeaders = $original->headers->all();
        $typedHeaders = $typed->headers->all();
        // HTTP Date is generated per response; all other header names/values remain exact.
        unset($originalHeaders['date'], $typedHeaders['date']);
        $this->assertSame($originalHeaders, $typedHeaders);
        $this->assertSame($original->baseResponse->getEncodingOptions(), $typed->baseResponse->getEncodingOptions());
    }

    public function test_unknown_publication_failures_and_legacy_constructors_stay_raw(): void
    {
        $detail = 'beam-docs::messages.search / raw Δ :detail <tag> [!! inner stays raw !!]';
        $this->partialMock(PublicationService::class, function ($mock) use ($detail) {
            $mock->shouldReceive('capture')->twice()->andThrow(new PublicationFailure($detail));
            $mock->shouldReceive('retry')->twice()->andThrow(new PublicationFailure($detail));
        });
        foreach (['en', 'en-XA'] as $locale) {
            app()->setLocale($locale);
            $this->withHeaders(['Accept-Language' => $locale]);
            foreach ([['/beam/docs/publications', ['version' => 'raw-1'], 422],
                ['/beam/docs/publications/00000000-0000-0000-0000-000000000001/retry', [], 409]] as [$uri, $input, $status]) {
                $response = $this->postJson($uri, $input)->assertStatus($status);
                $expected = $locale === 'en' ? $detail : str_replace(':detail', $detail, PseudoLocale::transform(':detail'));
                $this->assertSame(['message' => $expected], $response->json());
                $this->assertStringContainsString($detail, $response->json('message'));
                $this->assertArrayNotHasKey('code', $response->json());
            }
        }
        $raw = new CodedPublicationFailure('legacy raw', 17, new RuntimeException('previous'));
        $this->assertSame('legacy raw', $raw->getMessage());
        $this->assertSame(17, $raw->getCode());
        $this->assertNull($raw->explicitCodedError());
        $this->assertNull(DocsResponses::render($raw, Request::create('/json', server: ['HTTP_ACCEPT' => 'application/json'])));
    }

    public function test_worker_policy_and_unknown_failure_storage_remain_raw_under_pseudo_locale(): void
    {
        app()->setLocale('en-XA');
        $attempt = $this->service()->capture('worker-policy-1');
        $snapshot = $attempt->snapshot;
        config(['beam.docs.enabled' => false]);
        $result = $this->service()->run($attempt->id);
        $this->assertSame('failed', $result->status);
        $this->assertSame('Documentation is disabled.', $result->error);
        $this->assertSame($snapshot, $result->snapshot);
        $this->assertFalse(PseudoLocale::isMarked($result->error));
        config(['beam.docs.enabled' => true]);
        $this->partialMock(ScalarCli::class, function ($mock) {
            $mock->shouldReceive('publish')->once()->andThrow(new RuntimeException('untrusted remote bytes'));
        });
        $unknown = $this->service()->run($this->service()->capture('worker-unknown-1')->id);
        $this->assertSame('The publishing worker failed. Check worker configuration and connectivity before retrying.', $unknown->error);
        $this->assertFalse(PseudoLocale::isMarked($unknown->error));
        $this->assertSame([], $this->calls());
    }
}
