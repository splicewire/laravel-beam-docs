<?php

namespace Splicewire\Beam\Docs\Tests\Install;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Rushing\Popcorn\Registries\RelativeUriKey;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Install\BeamInstallManifest;
use Symfony\Component\Yaml\Yaml;

class GenerateOpenApiCommandTest extends TestCase
{
    private string $diskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->diskRoot = sys_get_temp_dir().'/beam-docs-generate-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->diskRoot);
        config(['filesystems.disks.local.root' => $this->diskRoot]);
        $this->app->make('filesystem')->forgetDisk('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->diskRoot);
        parent::tearDown();
    }

    public function test_installed_documentation_generates_and_serves_the_public_reference(): void
    {
        Route::get('api/docs-test/widgets', [GenerationFixtureController::class, 'index']);

        $this->artisan('splicewire:beam:docs:generate')->assertExitCode(0);

        $artifact = $this->diskRoot.'/scribe/openapi.yaml';
        $this->assertFileExists($artifact);
        $document = Yaml::parseFile($artifact);
        $this->assertArrayHasKey('/api/docs-test/widgets', $document['paths']);
        $this->get('/beam/openapi.yaml')->assertOk()->assertContent(File::get($artifact));
        $this->assertNull(Route::getRoutes()->getByName('scribe'));

        $step = $this->app->make(BeamInstallManifest::class)->resolve(
            RelativeUriKey::of('splicewire/laravel-beam-docs'),
        );
        $this->assertContains('splicewire:beam:docs:generate', $step->commands);
    }

    public function test_a_failed_generator_does_not_claim_an_existing_artifact_is_fresh(): void
    {
        File::ensureDirectoryExists($this->diskRoot.'/scribe');
        File::put($this->diskRoot.'/scribe/openapi.yaml', 'stale');
        Artisan::command('scribe:generate', fn () => 1);

        $this->artisan('splicewire:beam:docs:generate')->assertExitCode(1);
    }

    public function test_disabled_documentation_does_not_invoke_the_generator(): void
    {
        config(['beam.docs.enabled' => false]);
        $called = false;
        Artisan::command('scribe:generate', function () use (&$called): void {
            $called = true;
        });

        $this->artisan('splicewire:beam:docs:generate')->assertExitCode(0);

        $this->assertFalse($called);
        $this->assertFileDoesNotExist($this->diskRoot.'/scribe/openapi.yaml');
    }
}

class GenerationFixtureController
{
    #[ResponseFromData(GenerationFixtureData::class)]
    public function index(): GenerationFixtureData
    {
        return new GenerationFixtureData('widget');
    }
}

class GenerationFixtureData extends Data
{
    public function __construct(public string $name) {}
}
