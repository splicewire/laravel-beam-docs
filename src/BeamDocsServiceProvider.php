<?php

namespace Splicewire\Beam\Docs;

use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Splicewire\Beam\Docs\Console\GenerateOpenApiCommand;
use Splicewire\Beam\Docs\Console\PublishScalarCommand;
use Splicewire\Beam\Docs\Http\DocsReadAccess;
use Splicewire\Beam\Doctor\BeamDoctorManifest;
use Splicewire\Beam\Doctor\ScribeOutputContractAudit;
use Splicewire\Beam\Http\OpenApiSpecController;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\OpenApi\ConfiguredArtifactSpecSource;
use Splicewire\Beam\OpenApi\OpenApiSpecSource;
use Splicewire\Beam\Scribe\FrameEndpointUrl;
use Splicewire\Beam\Surface\OpenApiSpecCorroborator;
use Splicewire\Beam\Surface\RuntimeCorroborator;
use Splicewire\Beam\Surgeon\SdkNameConventionAudit;

class BeamDocsServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package->name('laravel-beam-docs')->hasConfigFile('beam/docs')
            ->hasMigrations(['create_beam_docs_publications_table', 'shared/adopt_docs_entry_requirement']);
    }

    public function packageRegistered(): void
    {
        // Run before any provider boots: Scribe mounts its routes during boot, so suppressing them
        // afterwards would leave an unguarded documentation door beside our visibility policy.
        $this->app->booting(function (): void {
            if (! config('scribe')) {
                config(['scribe' => require dirname(__DIR__).'/stubs/scribe/scribe.php']);
            }

            config(['scribe.laravel.add_routes' => false]);
        });

        $this->app->bind(OpenApiSpecSource::class, ConfiguredArtifactSpecSource::class);
        $this->app->bind(OpenApiSpecCorroborator::class, fn ($app) => new OpenApiSpecCorroborator(
            $app->make(RuntimeCorroborator::class),
        ));
        $this->app->bind(SdkNameConventionAudit::class, fn () => SdkNameConventionAudit::forClientPackage());
        $this->app->register(DocsUxServiceProvider::class);
    }

    public function packageBooted(): void
    {
        FrameEndpointUrl::register();

        $this->publishes([
            dirname(__DIR__).'/stubs/scribe/scribe.php' => config_path('scribe.php'),
        ], 'beam-scribe');

        $this->commands([GenerateOpenApiCommand::class, PublishScalarCommand::class]);

        $this->app->make(BeamInstallManifest::class)->register(
            package: 'splicewire/laravel-beam-docs',
            publishTags: ['beam-docs-config', 'beam-docs-migrations', 'beam-scribe'],
            migrates: true,
            order: 220,
            commands: ['splicewire:beam:docs:generate'],
        );

        $manifest = $this->app->make(BeamDoctorManifest::class);
        $manifest->register('splicewire/laravel-beam-docs', ScribeOutputContractAudit::class);
        if (interface_exists(\Rushing\Surgeon\Operation\SuggestsOperations::class)) {
            $manifest->register('splicewire/laravel-beam-docs', SdkNameConventionAudit::class);
        }

        $middleware = config('beam.docs.openapi.middleware') ?? config('beam.core.openapi.middleware', []);
        Route::middleware(['web', DocsReadAccess::class, ...(array) $middleware])->group(function (): void {
            Route::get('beam/openapi.yaml', [OpenApiSpecController::class, 'yaml'])->name('beam.openapi.yaml');
            Route::get('beam/openapi.json', [OpenApiSpecController::class, 'json'])->name('beam.openapi.json');
        });

        $this->loadRoutesFrom(dirname(__DIR__).'/routes/publications.php');
    }
}
