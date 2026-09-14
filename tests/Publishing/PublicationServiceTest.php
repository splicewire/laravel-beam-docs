<?php

namespace Splicewire\Beam\Docs\Tests\Publishing;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Splicewire\Beam\Docs\Publishing\Jobs\PublishToScalar;
use Splicewire\Beam\Docs\Publishing\Publication;
use Splicewire\Beam\Docs\Publishing\PublicationFailure;
use Splicewire\Beam\Docs\Publishing\ScalarCli;

class PublicationServiceTest extends PublicationTestCase
{
    public function test_it_uploads_the_captured_bytes_with_isolated_auth_and_a_validated_link(): void
    {
        $originalHome = getenv('HOME');
        $snapshot = $this->source->body;
        $publication = $this->service()->capture('1.2.3');
        $this->source->body = 'changed after capture';
        $result = $this->service()->run($publication->id);
        $this->assertSame('succeeded', $result->status, $result->error ?? '');
        $this->assertSame(hash('sha256', $snapshot), $result->sha256);
        $this->assertSame(ScalarCli::registryUrl($result), $this->service()->latestSuccessfulUrl());
        $calls = $this->calls();
        $this->assertSame(['--version', 'document validate', 'auth login', 'registry list', 'registry publish'], array_map(fn ($call) => implode(' ', array_slice($call['args'], 0, 2)), $calls));
        $this->assertSame($snapshot, $calls[1]['body']);
        $this->assertSame($snapshot, $calls[4]['body']);
        $this->assertSame(['--namespace', 'test-team', '--slug', 'test-api', '--version', '1.2.3'], array_slice($calls[4]['args'], 3));
        $this->assertSame([false, false, true, false, false], array_column($calls, 'tokenPresent'));
        foreach ($calls as $call) {
            $this->assertSame(0700, $call['homeMode']);
            $this->assertDirectoryDoesNotExist($call['home']);
            $this->assertNotContains('personal-secret-test-token', $call['args']);
        }
        $this->assertSame(0600, $calls[4]['artifactMode']);
        $this->assertSame($originalHome, getenv('HOME'));
    }

    public function test_a_private_snapshot_stays_private_if_site_policy_later_becomes_public(): void
    {
        config(['beam.docs.visibility' => 'private']);
        $publication = $this->service()->capture('private-1');
        config(['beam.docs.visibility' => 'public']);
        $this->assertSame('succeeded', $this->service()->run($publication->id)->status);
        $this->assertContains('--private', $this->calls()[4]['args']);
        $this->assertNull($this->service()->latestSuccessfulUrl());
    }

    public function test_semver_build_metadata_matches_the_cli_registry_url(): void
    {
        $result = $this->service()->run($this->service()->capture('1.2.3+build.4')->id);
        $this->assertSame('succeeded', $result->status, $result->error ?? '');
        $this->assertSame('https://registry.scalar.com/@test-team/apis/test-api@1.2.3+build.4', $result->registry_url);
    }

    public function test_policy_changes_refuse_a_queued_public_upload_before_starting_a_process(): void
    {
        foreach ([['beam.docs.visibility' => 'private'], ['beam.docs.enabled' => false], ['beam.docs.scalar.enabled' => false], ['beam.docs.scalar.slug' => 'different-api']] as $change) {
            config(['beam.docs.visibility' => 'public', 'beam.docs.enabled' => true, 'beam.docs.scalar.enabled' => true, 'beam.docs.scalar.slug' => 'test-api']);
            $publication = $this->service()->capture('policy-1');
            config($change);
            $this->assertSame('failed', $this->service()->run($publication->id)->status);
        }
        $this->assertSame([], $this->calls());
    }

    public function test_every_cli_failure_is_terminal_sanitized_and_cleans_the_private_home(): void
    {
        foreach (['document validate', 'auth login', 'registry list', 'registry publish'] as $stage) {
            $this->fake(['failure' => $stage]);
            $before = count($this->calls());
            $result = $this->service()->run($this->service()->capture('failure-1')->id);
            $this->assertSame('failed', $result->status);
            $this->assertNotNull($result->finished_at);
            $this->assertStringNotContainsString('secret', $result->error);
            $this->assertStringNotContainsString('upstream-exchanged', $result->error);
            $this->assertLessThanOrEqual(1000, strlen($result->error));
            $this->assertNull($result->registry_url);
            $calls = array_slice($this->calls(), $before);
            $this->assertSame($stage, implode(' ', array_slice($calls[array_key_last($calls)]['args'], 0, 2)));
            foreach ($this->calls() as $call) {
                $this->assertDirectoryDoesNotExist($call['home']);
            }
        }
    }

    public function test_timeout_and_unrecognized_success_fail_truthfully(): void
    {
        config(['beam.docs.scalar.process_timeout' => 0.1]);
        $this->fake(['sleep' => 'registry publish']);
        $result = $this->service()->run($this->service()->capture('timeout-1')->id);
        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('timed out', $result->error);
        config(['beam.docs.scalar.process_timeout' => 5]);
        $this->fake(['url' => 'https://registry.scalar.com.evil.test/stolen']);
        $result = $this->service()->run($this->service()->capture('bad-url-1')->id);
        $this->assertSame('failed', $result->status);
        $this->assertNull($result->registry_url);
        $this->fake(['version' => '2.0.0']);
        $result = $this->service()->run($this->service()->capture('wrong-cli-1')->id);
        $this->assertStringContainsString('requires @scalar/cli 2.1.0', $result->error);
    }

    public function test_retries_preserve_snapshot_and_destination_and_never_overwrite_remote_versions(): void
    {
        $this->fake(['failure' => 'registry publish', 'message' => 'Version already exists 409']);
        $failed = $this->service()->run($this->service()->capture('1.2.3')->id);
        $this->assertStringContainsString('never overwritten', $failed->error);
        $this->source->body = 'new source';
        $retry = $this->service()->retry($failed->id);
        $this->assertSame($retry->id, $this->service()->retry($failed->id)->id);
        $this->assertSame($failed->snapshot, $retry->snapshot);
        $this->assertSame($failed->sha256, $retry->sha256);
        $this->assertSame($failed->id, $retry->retry_of);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->fake([]);
        $this->assertSame('succeeded', $this->service()->run($retry->id)->status);
        foreach ($this->calls() as $call) {
            $this->assertNotContains('--force', $call['args']);
        }
        $this->expectException(PublicationFailure::class);
        $this->service()->retry($retry->id);
    }

    public function test_duplicate_worker_execution_does_not_upload_twice_or_change_terminal_state(): void
    {
        $publication = $this->service()->capture('once-1');
        Publication::query()->whereKey($publication->id)->update(['status' => 'running']);
        $this->assertSame('running', $this->service()->run($publication->id)->status);
        $this->assertSame([], $this->calls());
        Publication::query()->whereKey($publication->id)->update(['status' => 'queued']);
        $this->service()->run($publication->id);
        $before = $this->calls();
        $this->assertSame('succeeded', $this->service()->run($publication->id)->status);
        $this->assertSame($before, $this->calls());
    }

    public function test_link_uses_a_previous_success_after_later_failure_and_honors_current_configuration(): void
    {
        $success = $this->service()->run($this->service()->capture('1.0.0')->id);
        $this->assertSame('succeeded', $success->status, $success->error ?? '');
        $this->assertNotNull($success->registry_url);
        $this->fake(['failure' => 'registry publish']);
        $this->service()->run($this->service()->capture('2.0.0')->id);
        $this->assertSame($success->registry_url, $this->service()->latestSuccessfulUrl());
        config(['beam.docs.scalar.show_link' => false]);
        $this->assertNull($this->service()->latestSuccessfulUrl());
        config(['beam.docs.scalar.show_link' => true, 'beam.docs.scalar.slug' => 'different']);
        $this->assertNull($this->service()->latestSuccessfulUrl());
    }

    public function test_missing_artifacts_and_tampered_snapshots_never_upload(): void
    {
        $attempt = $this->service()->capture('1.0.0');
        Publication::query()->whereKey($attempt->id)->update(['snapshot' => 'tampered']);
        $this->assertStringContainsString('integrity check', $this->service()->run($attempt->id)->error);
        $this->assertSame([], $this->calls());
        $this->source->body = null;
        $this->expectException(PublicationFailure::class);
        $this->service()->capture('2.0.0');
    }

    public function test_private_upload_refuses_existing_public_destination_and_unknown_listing(): void
    {
        config(['beam.docs.visibility' => 'private']);
        foreach (["│ Title │ Name │ Version │ Access │\n│ Test │ @test-team/test-api │ 1.0.0 │ Public │\n", 'unrecognized output', ''] as $listing) {
            $this->fake(['listing' => $listing]);
            $result = $this->service()->run($this->service()->capture('private-1')->id);
            $this->assertSame('failed', $result->status);
        }
        foreach ($this->calls() as $call) {
            $this->assertNotSame(['registry', 'publish'], array_slice($call['args'], 0, 2));
        }
        $this->fake(['listing' => "│ Title │ Name │ Version │ Access │\n│ Test │ @test-team/test-api │ 1.0.0 │ Private │\n"]);
        $this->assertSame('succeeded', $this->service()->run($this->service()->capture('private-2')->id)->status);
    }

    public function test_job_failure_is_durable_and_tenant_context_is_refused(): void
    {
        $attempt = $this->service()->capture('queued-1');
        Bus::fake();
        config(['beam.docs.scalar.queue' => 'docs']);
        $this->service()->enqueue($attempt);
        Bus::assertDispatched(PublishToScalar::class, fn ($job) => $job->publicationId === $attempt->id && $job->queue === 'docs');
        (new PublishToScalar($attempt->id))->failed(new \RuntimeException('sensitive exception'));
        $this->assertSame('failed', $attempt->fresh()->status);
        $this->assertStringNotContainsString('sensitive', $attempt->fresh()->error);
        $this->app->instance('tenancy', (object) ['initialized' => true]);
        $this->expectException(PublicationFailure::class);
        $this->service()->capture('tenant-1');
    }

    public function test_policy_is_checked_again_immediately_before_upload(): void
    {
        $this->app->bind(ScalarCli::class, fn () => new class extends ScalarCli
        {
            public function publish(Publication $publication, callable $checkPolicy): string
            {
                return parent::publish($publication, function () use ($checkPolicy): void {
                    config(['beam.docs.visibility' => 'private']);
                    $checkPolicy();
                });
            }
        });
        $result = $this->service()->run($this->service()->capture('policy-race')->id);
        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('while documentation is private', $result->error);
        $this->assertSame('registry list', implode(' ', array_slice($this->calls()[3]['args'], 0, 2)));
        $this->assertCount(4, $this->calls());
    }

    public function test_excessive_output_fails_and_snapshot_columns_cannot_be_edited(): void
    {
        $this->fake(['excessive' => 'registry list']);
        $result = $this->service()->run($this->service()->capture('bounded-output')->id);
        $this->assertSame('failed', $result->status);
        $this->assertStringContainsString('excessive output', $result->error);
        $this->assertDirectoryDoesNotExist($this->calls()[0]['home']);
        $this->expectException(\LogicException::class);
        $result->update(['snapshot' => 'edited']);
    }

    public function test_two_real_workers_claim_one_attempt_once(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The concurrent-worker proof requires pcntl.');
        }
        $database = $this->work.'/workers.sqlite';
        touch($database);
        config(['database.connections.testing.database' => $database]);
        DB::purge('testing');
        $migration = require dirname(__DIR__, 2).'/database/migrations/create_beam_docs_publications_table.php.stub';
        $migration->up();
        $attempt = $this->service()->capture('concurrent-1');
        DB::disconnect('testing');
        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Could not start the concurrent publishing worker.');
            }
            if ($pid === 0) {
                try {
                    DB::purge('testing');
                    $this->service()->run($attempt->id);
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge('testing');
        $this->assertSame('succeeded', $this->service()->find($attempt->id)->status);
        $uploads = array_filter($this->calls(), fn ($call) => array_slice($call['args'], 0, 2) === ['registry', 'publish']);
        $this->assertCount(1, $uploads);
    }
}
