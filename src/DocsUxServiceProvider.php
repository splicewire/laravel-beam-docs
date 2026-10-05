<?php

namespace Splicewire\Beam\Docs;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Docs\Access\DocsEntryRequirement;
use Splicewire\Beam\Docs\Http\DocsPageController;
use Splicewire\Beam\Docs\Http\DocsReadAccess;
use Splicewire\Beam\Docs\Publishing\Http\EnsurePublishingHost;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\Seed\BeamSeedManifest;

class DocsUxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('beam.ux.requirement.beam-docs', DocsEntryRequirement::class);
    }

    public function boot(): void
    {
        config(['beam.ux.chrome.registered' => array_values(array_unique([...config('beam.ux.chrome.registered', []), 'DocsLayout']))]);
        Route::middleware(['web', EnsurePublishingHost::class])->group(function (): void {
            Route::get('beam/docs/manage', [DocsPageController::class, 'manage'])->name('beam.docs.manage');
        });
        Route::middleware(['web', DocsReadAccess::class])->group(function (): void {
            Route::get('beam/docs/registry-link', [DocsPageController::class, 'registryLink'])->name('beam.docs.registry-link');
        });
        $this->publishes([__DIR__.'/../stubs/docs' => resource_path('beam-ux/docs')], 'beam-ux-docs');
        $this->app->make(BeamSeedManifest::class)->register('splicewire/laravel-beam-docs', DocsSeeder::class, order: 25);
    }
}
