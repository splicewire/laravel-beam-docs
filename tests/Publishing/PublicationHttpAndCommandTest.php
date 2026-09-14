<?php

namespace Splicewire\Beam\Docs\Tests\Publishing;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Docs\Publishing\Jobs\PublishToScalar;
use Splicewire\Beam\Docs\Publishing\Publication;

class PublicationHttpAndCommandTest extends PublicationTestCase
{
    public function test_operator_create_status_list_and_retry_use_the_declared_contract(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));
        Gate::define('beam-docs.publish', fn ($user) => $user->id === 1);
        Bus::fake();
        $response = $this->postJson('/beam/docs/publications', [
            'version' => '1.0.0',
            'artifact' => '/etc/passwd',
            'namespace' => 'injected-team',
            'isPrivate' => true,
        ])->assertAccepted()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.namespace', 'test-team')->assertJsonPath('data.isPrivate', false);
        $id = $response->json('data.id');
        $this->assertArrayNotHasKey('snapshot', $response->json('data'));
        $this->assertArrayNotHasKey('token', $response->json('data'));
        Bus::assertDispatched(PublishToScalar::class, fn ($job) => $job->publicationId === $id);
        $this->getJson('/beam/docs/publications/'.$id)->assertOk()->assertJsonPath('data.id', $id);
        $this->getJson('/beam/docs/publications')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->fake(['failure' => 'registry publish']);
        $this->service()->run($id);
        $retry = $this->postJson('/beam/docs/publications/'.$id.'/retry')->assertAccepted()->assertJsonPath('data.retryOf', $id);
        $this->assertNotSame($id, $retry->json('data.id'));
    }

    public function test_all_operator_reads_and_writes_deny_without_permission_before_validation_or_lookup(): void
    {
        $attempt = $this->service()->capture('1.0.0');
        foreach ([null, new GenericUser(['id' => 2])] as $user) {
            if ($user !== null) {
                $this->actingAs($user);
            }
            Gate::define('beam-docs.publish', fn ($user) => false);
            $this->getJson('/beam/docs/publications')->assertForbidden();
            $this->getJson('/beam/docs/publications/'.$attempt->id)->assertForbidden();
            $this->postJson('/beam/docs/publications', ['version' => ''])->assertForbidden();
            $this->postJson('/beam/docs/publications/'.$attempt->id.'/retry')->assertForbidden();
        }
        $this->assertSame(1, Publication::query()->count());
        $this->assertSame([], $this->calls());
    }

    public function test_invalid_versions_and_tenant_requests_cannot_create_attempts(): void
    {
        $this->actingAs(new GenericUser(['id' => 1]));
        Gate::define('beam-docs.publish', fn ($user) => true);
        foreach (['', '--force', 'version with spaces', '../1.0.0'] as $version) {
            $this->postJson('/beam/docs/publications', ['version' => $version])->assertUnprocessable();
        }
        $this->app->instance('tenancy', (object) ['initialized' => true]);
        $this->postJson('/beam/docs/publications', ['version' => '1.0.0'])->assertForbidden();
        $this->getJson('/beam/docs/publications')->assertForbidden();
        $this->assertSame(0, Publication::query()->count());
    }

    public function test_reader_link_honors_table_prefix_visibility_and_tenant_isolation(): void
    {
        Schema::rename('beam_docs_publications', 'custom_docs_publications');
        config(['beam.core.table_prefix' => 'custom_']);
        $attempt = $this->service()->capture('1.0.0');
        $this->service()->run($attempt->id);
        $this->getJson('/beam/docs/registry-link')->assertOk()
            ->assertJsonPath('data.url', 'https://registry.scalar.com/@test-team/apis/test-api@1.0.0');
        config(['beam.docs.scalar.show_link' => false]);
        $this->getJson('/beam/docs/registry-link')->assertOk()->assertJsonPath('data.url', null);
        config(['beam.docs.scalar.show_link' => true, 'beam.docs.visibility' => 'private']);
        $this->getJson('/beam/docs/registry-link')->assertNotFound();
        config(['beam.docs.visibility' => 'public']);
        $this->app->instance('tenancy', (object) ['initialized' => true]);
        $this->getJson('/beam/docs/registry-link')->assertOk()->assertJsonPath('data.url', null);
    }

    public function test_the_command_publishes_synchronously_without_an_http_gate_and_exits_on_failures(): void
    {
        Gate::define('beam-docs.publish', fn ($user) => false);
        $this->artisan('splicewire:beam:docs:publish', ['version' => '1.0.0', '--json' => true])->assertSuccessful();
        $success = Publication::query()->sole();
        $this->assertSame('succeeded', $success->status);
        $this->artisan('splicewire:beam:docs:publish', ['--status' => $success->id, '--json' => true])->assertSuccessful();
        $before = $this->calls();
        $this->fake(['failure' => 'registry publish']);
        $this->artisan('splicewire:beam:docs:publish', ['version' => '2.0.0'])->assertFailed();
        $failed = Publication::query()->where('status', 'failed')->sole();
        $this->assertNotSame($before, $this->calls());
        $this->fake([]);
        $this->artisan('splicewire:beam:docs:publish', ['--retry' => $failed->id])->assertSuccessful();
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame('succeeded', Publication::query()->where('retry_of', $failed->id)->sole()->status);
        $this->artisan('splicewire:beam:docs:publish', ['version' => '3.0.0', '--retry' => $failed->id])->assertExitCode(2);
    }
}
