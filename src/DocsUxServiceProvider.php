<?php

namespace Splicewire\Beam\Docs;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Docs\Access\DocsEntryRequirement;
use Splicewire\Beam\Docs\Chrome\DocsChrome;
use Splicewire\Beam\Docs\Http\DocsPageController;
use Splicewire\Beam\Docs\Http\DocsReadAccess;
use Splicewire\Beam\Docs\Http\RegistryDocsController;
use Splicewire\Beam\Docs\Publishing\Http\EnsurePublishingHost;
use Splicewire\Beam\Docs\Registries\BeamDocsLinkResolver;
use Splicewire\Beam\Docs\Registries\FamilyRegistryDocSources;
use Splicewire\Beam\Docs\Registries\RegistryDocsNav;
use Splicewire\Beam\Docs\Registries\RegistryDocsNavProjector;
use Splicewire\Beam\Docs\Search\DocsSearchOp;
use Splicewire\Beam\Docs\Seed\DocsProvenanceTemplates;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\Docs\Theme\DocsThemeNamespaceSource;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Docs\DocsLinkResolver;
use Splicewire\Beam\Ux\Http\EntryPageProps;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\ProvenanceTemplates;
use Splicewire\Beam\Ux\Schema\ThemeSchemas;

class DocsUxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('beam.ux.requirement.beam-docs', DocsEntryRequirement::class);
        // registry-docs ticket 02: supersede beam-ux's null DocsLinkResolver default. A plain bind()
        // behind the posture "beam-docs is installed" (host-bindings supersession lane, the
        // beam-tenancy shape) — order-independent against beam-ux's bindIf by construction. The
        // resolver reads the committed spine catalog and the entries table; there is no docs
        // registry to read instead (beam-ux ADR-0210, amended ruling 1).
        $this->app->bind(DocsLinkResolver::class, BeamDocsLinkResolver::class);
        // registry-docs ticket 08 (ticket 01 ruling 7): the registry section of the `site` nav is the
        // catalog grouped by cluster with availability annotations, composed into the projector's
        // already-gated tree. Decorated rather than replaced, so beam-ux's publish/access passes run first.
        $this->app->extend(NavProjector::class, fn (NavProjector $projector, $app) => $projector instanceof RegistryDocsNavProjector
            ? $projector
            : new RegistryDocsNavProjector($projector, $app->make(RegistryDocsNav::class)));
        if (defined(ThemeSchemas::class.'::SOURCES')) {
            $this->app->tag([DocsThemeNamespaceSource::class], ThemeSchemas::SOURCES);
        }

        // EP-42 S1: <ApiReference /> on this package's docs page is placeable where that page holds blocks. Offered only
        // to MDX pages (laravel-beam-ux's MDX_SOURCES); a laravel-beam-ux that predates the tag offers it to nothing.
        if (defined(ManifestProfileResolver::class.'::MDX_SOURCES')) {
            // registry-docs ticket 08: <RegistryRegistrants root="…" /> on the registry pages, offered the same way.
            $this->app->tag([Editor\ApiReferenceNodeSource::class, Editor\RegistryRegistrantsNodeSource::class], ManifestProfileResolver::MDX_SOURCES);
        }
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
            // registry-docs ticket 02: the registry deep link. `{root}` is the dotted root VERBATIM
            // (the default pattern accepts dots; no slugifier, ruling 3 / R11). Mounted beside the
            // package's other docs routes, never at the public page path — a package route there
            // would shadow the host catch-all that serves the pages (ADR-0209 §2). Singular
            // `registry/` (ticket 08): the plural form IS the public page path on a host whose docs
            // segment is `/beam/docs` (the www spine), where it shadowed every served registry page
            // and redirected each one to itself.
            Route::get('beam/docs/registry/{root}', [RegistryDocsController::class, 'show'])->name('beam.docs.registries.show');
            // registry-docs ticket 03: the live registrant panel (host-variant, never committed).
            Route::get('beam/docs/registries/{root}/registrants', [RegistryDocsController::class, 'registrants'])->name('beam.docs.registries.registrants');
        });
        // docs.search (DOCS-14, DM6): a guest reads public docs as a guest, so it mounts beside the docs pages' own gate
        // (DocsReadAccess), never in a tenant API group; the throttle bounds a public endpoint. Every row is gated per
        // principal inside the op.
        Route::middleware(['web', DocsReadAccess::class, 'throttle:60,1'])->group(function (): void {
            Particle::ops('beam/docs', 'beam-docs', [DocsSearchOp::class]);
        });
        $this->publishes([__DIR__.'/../stubs/docs' => resource_path('beam-ux/docs')], 'beam-ux-docs');
        $this->app->make(BeamSeedManifest::class)->register('splicewire/laravel-beam-docs', DocsSeeder::class, order: 25);
        // registry-docs ticket 02: the generic family `docs/registries/` source rows (ruling 2).
        // On booted(), after every provider, so a package guarding its own docs-bundle row by
        // `package:` stamp at its boot never meets a generic row first and skips its own.
        $this->app->booted(fn () => $this->app->make(FamilyRegistryDocSources::class)->contribute());
        // DOCS-06b: the stubs this package seeds, current and the prior one found in the field, for the provenance backfill.
        $this->app->make(ProvenanceTemplates::class)->register(fn (): array => DocsProvenanceTemplates::all());
    }
}
