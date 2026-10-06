<?php

namespace Splicewire\Beam\Docs;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Docs\Access\DocsEntryRequirement;
use Splicewire\Beam\Docs\Chrome\DocsChrome;
use Splicewire\Beam\Docs\Search\DocsSearchOp;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Docs\Http\DocsPageController;
use Splicewire\Beam\Docs\Http\DocsReadAccess;
use Splicewire\Beam\Docs\Publishing\Http\EnsurePublishingHost;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Ux\Http\EntryPageProps;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class DocsUxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('beam.ux.requirement.beam-docs', DocsEntryRequirement::class);
    }

    public function boot(): void
    {
        config(['beam.ux.chrome.registered' => array_values(array_unique([...config('beam.ux.chrome.registered', []), 'DocsLayout']))]);
        // Every DocsLayout page carries its header data as `docsChrome` (DOCS-12, DM4): one server read, so no host spells
        // a header. Pages outside docs are untouched.
        $this->app->make(EntryPageProps::class)->contribute(
            DocsChrome::LAYOUT,
            fn (BeamUxEntry $entry, array $chain, mixed $actor) => [
                'docsChrome' => $this->app->make(DocsChrome::class)->for($entry, $actor instanceof Authenticatable ? $actor : null)->toArray(),
            ],
        );
        Route::middleware(['web', EnsurePublishingHost::class])->group(function (): void {
            Route::get('beam/docs/manage', [DocsPageController::class, 'manage'])->name('beam.docs.manage');
        });
        Route::middleware(['web', DocsReadAccess::class])->group(function (): void {
            Route::get('beam/docs/registry-link', [DocsPageController::class, 'registryLink'])->name('beam.docs.registry-link');
        });
        // docs.search (DOCS-14, DM6): a guest reads public docs as a guest, so it mounts beside the docs pages' own gate
        // (DocsReadAccess), never in a tenant API group; the throttle bounds a public endpoint. Every row is gated per
        // principal inside the op.
        Route::middleware(['web', DocsReadAccess::class, 'throttle:60,1'])->group(function (): void {
            Particle::ops('beam/docs', 'beam-docs', [DocsSearchOp::class]);
        });
        $this->publishes([__DIR__.'/../stubs/docs' => resource_path('beam-ux/docs')], 'beam-ux-docs');
        $this->app->make(BeamSeedManifest::class)->register('splicewire/laravel-beam-docs', DocsSeeder::class, order: 25);
        // DOCS-06b: the stubs this package seeds, current and the prior one found in the field, for the provenance backfill.
        $this->app->make(\Splicewire\Beam\Ux\Provenance\ProvenanceTemplates::class)->register(fn (): array => \Splicewire\Beam\Docs\Seed\DocsProvenanceTemplates::all());
    }
}
