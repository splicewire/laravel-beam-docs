<?php

namespace Splicewire\Beam\Docs\Http;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Docs\Data\DocsPublishingPageData;
use Splicewire\Beam\Docs\Data\RegistryLinkData;
use Splicewire\Beam\Docs\Data\RegistryLinkResponseData;
use Splicewire\Beam\Docs\Publishing\PublicationService;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\Ux\Http\EntryRenderer;

class DocsPageController
{
    #[ResponseFromData(DocsPublishingPageData::class)]
    public function manage(EntryRenderer $renderer): mixed
    {
        abort_unless(config('beam.docs.enabled', true), 404);
        Gate::authorize('beam-docs.publish');
        $root = Schema::hasTable('beam_ux_entries') ? app(DocsSeeder::class)->existingRoot() : null;

        return $renderer->render('beam-docs/publishing', (new DocsPublishingPageData(
            route('beam.docs.publications.index', absolute: false), $root?->url(),
        ))->toArray());
    }

    #[ResponseFromData(RegistryLinkResponseData::class)]
    public function registryLink(PublicationService $publications): mixed
    {
        $host = ! app()->bound('tenancy') || ! app('tenancy')->initialized;
        $url = $host && Schema::hasTable('beam_docs_publications') ? $publications->latestSuccessfulUrl() : null;

        return response()->json((new RegistryLinkResponseData(new RegistryLinkData($url)))->toArray());
    }
}
