<?php

namespace Splicewire\Beam\Docs\Tests;

use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavTree;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Docs\Registries\RegistryDocsAvailability;
use Splicewire\Beam\Docs\Registries\RegistryDocsNav;
use Splicewire\Beam\Docs\Registries\RegistryRegistrants;
use Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Beta\BetaRegistry;
use Splicewire\Beam\Localization\PseudoLocale;
use Splicewire\Beam\Localization\TextNamespaces;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class LocalizationReadTest extends TestCase
{
    public function test_mounted_registrant_notes_keep_english_and_mark_pseudo_without_changing_registry_identity(): void
    {
        config(['beam.localization.pseudo' => true, 'beam.localization.http_profile' => true, 'beam.localization.supported' => ['en', 'fr', 'de']]);
        require_once __DIR__.'/Fixtures/RegistryDocs/packages/beta/src/BetaRegistry.php';
        app(RegistryIndex::class)->describe(app(BetaRegistry::class), by: 'splicewire/fixture-beta');
        $this->assertSame('beam-docs', app(TextNamespaces::class)->namespaceOf('splicewire/laravel-beam-docs'));
        foreach (['en', 'en-XA'] as $locale) {
            app()->setLocale($locale);
            $this->withHeaders(['Accept-Language' => $locale]);
            $responses = [
                $this->getJson('beam/docs/registries/fixture.nowhere/registrants')->assertOk(),
                $this->getJson('beam/docs/registries/fixture.beta/registrants')->assertOk(),
            ];
            $this->assertCount(2, $responses);
            $this->assertSame('fixture.nowhere', $responses[0]->json('root'));
            $this->assertFalse($responses[0]->json('describedHere'));
            $this->assertSame(BetaRegistry::class, $responses[1]->json('owner'));
            $this->assertSame('splicewire/fixture-beta', $responses[1]->json('describedBy'));
            foreach ($responses as $response) {
                $this->assertFalse($response->json('recordsRegistrants'));
                $this->assertNull($response->json('entries'));
                if ($locale === 'en-XA') {
                    $this->assertTrue(PseudoLocale::isMarked($response->json('note')));
                }
            }
            if ($locale === 'en') {
                $this->assertSame('not available on this host', $responses[0]->json('note'));
                $this->assertSame('This registry does not record who registered each entry.', $responses[1]->json('note'));
            }
        }
        $this->assertSame('not available on this host', RegistryRegistrants::NOT_HERE);
        $this->assertSame('This registry does not record who registered each entry.', RegistryRegistrants::NOT_RECORDED);
    }

    public function test_nav_runtime_annotations_preserve_catalog_content_and_mark_only_owned_wrappers(): void
    {
        config(['beam.localization.pseudo' => true, 'beam.localization.http_profile' => true, 'beam.localization.supported' => ['en', 'fr', 'de']]);
        $catalog = tempnam(sys_get_temp_dir(), 'docs-localization-catalog-');
        $rows = [
            'fixture.exempt' => ['owner' => null, 'doc' => ['kind' => 'exempt', 'class' => 'diagnostic-kind', 'reason' => 'Authored exemption reason', 'pointer' => 'docs/authored.mdx']],
            'fixture.gap' => ['owner' => null, 'doc' => null],
            'fixture.unavailable' => ['owner' => null, 'doc' => ['kind' => 'page', 'path' => 'docs/unavailable.mdx']],
            'fixture.unpublished' => ['owner' => null, 'doc' => ['kind' => 'page', 'path' => 'docs/unpublished.mdx']],
        ];
        file_put_contents($catalog, json_encode(['version' => 1, 'roots' => $rows, 'clusters' => []]));
        $raw = file_get_contents($catalog);
        config(['beam.docs.segment' => '/beam/docs', 'beam.docs.registries.catalog' => $catalog, 'beam.docs.registries.nav.widget' => 'links']);
        $this->app->instance(RegistryDocsAvailability::class, new class extends RegistryDocsAvailability
        {
            public function knownLocally(string $root): ?bool
            {
                return $root !== 'fixture.unavailable';
            }
        });
        try {
            foreach (['en', 'en-XA'] as $locale) {
                app()->setLocale($locale);
                $tree = NavTree::make([NavLink::make('Authored docs', '/beam/docs', children: [NavLink::make('Authored registries', '/beam/docs/registries')])]);
                $projected = app(RegistryDocsNav::class)->compose($tree)->toArray();
                $titles = [];
                $walk = function (array $nodes) use (&$walk, &$titles): void {
                    foreach ($nodes as $node) {
                        if (isset($node['title']) && str_starts_with($node['title'], 'fixture.') && str_contains($node['title'], '(')) {
                            $titles[] = $node['title'];
                        }
                        $walk($node['children'] ?? []);
                    }
                };
                $walk($projected['items']);
                $this->assertCount(4, $titles);
                foreach ($titles as $title) {
                    $this->assertSame($locale === 'en-XA', PseudoLocale::isMarked($title), $title);
                }
                $encoded = json_encode($projected, JSON_UNESCAPED_UNICODE);
                $this->assertStringContainsString('Authored docs', $encoded);
                $this->assertStringContainsString('Authored registries', $encoded);
                $this->assertStringContainsString('diagnostic-kind', $encoded);
                if ($locale === 'en') {
                    foreach (['no page: diagnostic-kind', 'not yet documented', 'not available on this host', 'not published on this host'] as $note) {
                        $this->assertStringContainsString($note, $encoded);
                    }
                }
                $this->assertSame($raw, file_get_contents($catalog));
            }
        } finally {
            unlink($catalog);
        }
    }

    public function test_config_nav_titles_use_declared_values_then_host_lines_without_package_defaults(): void
    {
        config(['beam.localization.pseudo' => true, 'beam.localization.http_profile' => true, 'beam.localization.supported' => ['en', 'fr', 'de']]);
        $work = sys_get_temp_dir().'/docs-localization-title-'.bin2hex(random_bytes(6));
        mkdir($work, 0700);
        $catalog = $work.'/catalog.json';
        file_put_contents($catalog, json_encode(['version' => 1, 'roots' => ['fixture.one' => ['owner' => null, 'doc' => null]], 'clusters' => []]));
        config(['beam.docs.segment' => '/beam/docs', 'beam.docs.registries.catalog' => $catalog, 'beam.docs.registries.nav.widget' => 'index']);
        $project = function (): string {
            $tree = NavTree::make([NavLink::make('Docs', '/beam/docs', children: [
                NavLink::make('Registries', '/beam/docs/registries', children: [NavLink::make('Authored unmanaged', '/beam/docs/registries/unlisted')]),
            ])]);

            return json_encode(app(RegistryDocsNav::class)->compose($tree)->toArray(), JSON_UNESCAPED_UNICODE);
        };
        try {
            foreach ([['Registry index', 'On this host'], ['Authored Index', 'Authored Unlisted']] as [$index, $unlisted]) {
                config(['beam.docs.registries.nav.index_title' => $index, 'beam.docs.registries.nav.unlisted_title' => $unlisted]);
                foreach (['en', 'fr', 'en-XA'] as $locale) {
                    app()->setLocale($locale);
                    $rendered = $project();
                    if ($locale === 'en-XA') {
                        $this->assertStringContainsString(PseudoLocale::transform($index), $rendered);
                        $this->assertStringContainsString(PseudoLocale::transform($unlisted), $rendered);
                    } else {
                        $this->assertStringContainsString('"title":"'.$index.'"', $rendered);
                        $this->assertStringContainsString('"title":"'.$unlisted.'"', $rendered);
                    }
                }
            }
            // A host language path supplies the vendor override; no namespace hint is added or replaced.
            $lang = $work.'/lang';
            mkdir($lang.'/vendor/beam-docs/de', 0700, true);
            file_put_contents($lang.'/vendor/beam-docs/de/docs.php', "<?php return ['registries' => ['nav' => ['index_title' => 'Host Index Line', 'unlisted_title' => 'Host Unlisted Line']]];");
            app('translation.loader')->addPath($lang);
            app()->setLocale('de');
            $rendered = $project();
            $this->assertStringContainsString('"title":"Host Index Line"', $rendered);
            $this->assertStringContainsString('"title":"Host Unlisted Line"', $rendered);
            $this->assertSame('Authored Index', config('beam.docs.registries.nav.index_title'));
            $this->assertSame('Authored Unlisted', config('beam.docs.registries.nav.unlisted_title'));
        } finally {
            if (is_file($work.'/lang/vendor/beam-docs/de/docs.php')) {
                unlink($work.'/lang/vendor/beam-docs/de/docs.php');
                foreach (['lang/vendor/beam-docs/de', 'lang/vendor/beam-docs', 'lang/vendor', 'lang'] as $dir) {
                    rmdir($work.'/'.$dir);
                }
            }
            unlink($catalog);
            rmdir($work);
        }
    }

    public function test_mounted_search_fallback_keeps_brand_query_and_url_raw_in_both_locales(): void
    {
        config(['beam.localization.pseudo' => true, 'beam.localization.http_profile' => true, 'beam.localization.supported' => ['en', 'fr', 'de']]);
        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        $site = BeamUxEntry::rootFor();
        BeamUxEntry::create(['slug' => 'localization-docs', 'title' => 'Authored docs', 'type' => 'page', 'format' => 'mdx', 'segment' => '/docs', 'parent_id' => $site->getKey(), 'layout' => 'DocsLayout']);
        config([
            'beam.docs.sources' => [['path' => 'docs', 'under' => null, 'type' => 'page', 'product' => 'splicewire']],
            'beam.brands' => ['beam' => ['name' => 'Authored Brand', 'home' => '/brand', 'docs' => '/brand/docs']],
        ]);
        foreach (['en', 'en-XA'] as $locale) {
            app()->setLocale($locale);
            $this->withHeaders(['Accept-Language' => $locale]);
            $response = $this->getJson('/beam/docs/search?'.http_build_query(['q' => 'raw query', 'root' => '/docs']))->assertOk();
            $body = $response->json();
            $fallback = $body['fallback'] ?? $body['data']['fallback'] ?? null;
            $this->assertIsArray($fallback);
            $this->assertSame('/brand/docs?q=raw%20query', $fallback['href']);
            $this->assertStringContainsString('Authored Brand', $fallback['label']);
            $this->assertSame($locale === 'en-XA', PseudoLocale::isMarked($fallback['label']));
            if ($locale === 'en') {
                $this->assertSame('Search Authored Brand docs', $fallback['label']);
            }
        }
    }
}
