<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Mockery;
use Splicewire\Beam\Docs\Chrome\DocsChrome;
use Splicewire\Beam\Docs\PhpApi\ArtifactWriter;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Ux\Access\EntryAccessGate;
use Splicewire\Beam\Ux\Access\TokenAccessGate;
use Splicewire\Beam\Ux\Containment\NavProjector;
use Splicewire\Beam\Ux\Http\EntryRenderer;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Sitemap\EntrySitemapSource;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * php-api-reference slice 2 (owner PRD §2, activation 2026-11-09): ONE requirement, `beam-docs-php-api`, decides whether
 * the PHP API reference exists, and every consumer reads it. Each surface is observed on its own (header, nav, search,
 * sitemap, page, both read ops), so a consumer that leaks while the others hide fails by name.
 *
 * Every "off" case first proves the same surfaces ON with a valid generated artifact, then breaks ONE condition, so it
 * tests the toggle rather than a deny-by-default that nothing ever lifts. Nothing is reseeded between the two halves:
 * the config and the artifact turn the seeded surface on and off.
 */
class PhpApiSurfaceTest extends TestCase
{
    private const TITLE = 'PHP API Reference';

    private const ALL_ON = ['header' => true, 'nav' => true, 'search' => true, 'sitemap' => true, 'page' => true, 'index' => true, 'symbol' => true];

    private const ALL_OFF = ['header' => false, 'nav' => false, 'search' => false, 'sitemap' => false, 'page' => false, 'index' => false, 'symbol' => false];

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().'/beam-docs-php-api-surface-'.bin2hex(random_bytes(6));
        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        Cache::flush();
        $this->app->bind(EntryAccessGate::class, fn () => new TokenAccessGate);
        $driver = Mockery::mock(StorageDriver::class);
        $driver->shouldReceive('read')->andReturnNull();
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $driver));
        $this->app->bind(EntryRenderer::class, fn () => new class implements EntryRenderer
        {
            public function render(string $page, array $props): Response
            {
                return new JsonResponse(['page' => $page, 'props' => $props]);
            }
        });

        $site = BeamUxEntry::rootFor();
        $docs = $this->entry('docs', 'Docs', ['segment' => '/docs', 'parent_id' => $site->getKey(), 'layout' => 'DocsLayout', 'requirements' => ['beam-docs']]);
        $this->entry('docs-api', 'API Reference', ['segment' => 'api', 'parent_id' => $docs->getKey(), 'template' => 'SpreadTemplate', 'nav_order' => 10]);
        $this->entry('docs-php-api', self::TITLE, ['segment' => 'php', 'parent_id' => $docs->getKey(), 'template' => 'SpreadTemplate', 'nav_order' => 20, 'requirements' => ['beam-docs-php-api']]);
        $this->entry('docs-guide', 'A guide', ['segment' => 'guide', 'parent_id' => $docs->getKey()]);
        Route::beamUxSite('site/entry');

        config([
            'app.url' => 'https://docs.test',
            'beam.docs.visibility' => 'public',
            'beam.docs.php_api.artifact' => $this->out,
            'beam.docs.php_api.audience' => null,
            'beam.docs.php_api.packages' => $this->fixtures(),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    /** @return array<string, string> */
    private function fixtures(bool $withApi = true): array
    {
        $base = dirname(__DIR__).'/Fixtures/PhpApi';

        return $withApi
            ? ['fixture/php-api-a' => $base.'/pkg-a', 'fixture/php-api-c' => $base.'/pkg-c']
            : ['fixture/php-api-c' => $base.'/pkg-c'];
    }

    /** @param  array<string, mixed>  $attributes */
    private function entry(string $slug, string $title, array $attributes): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => $slug, 'title' => $title, 'type' => 'page', 'format' => 'mdx', ...$attributes]);
    }

    /** The real generator, writing to the configured artifact path: what a host's build step does. */
    private function generate(): void
    {
        Artisan::call('splicewire:beam:docs:php-api:generate');
    }

    /** @return array<string, bool> */
    private function surfaces(?Authenticatable $as = null): array
    {
        $as === null ? $this->app['auth']->forgetGuards() : $this->actingAs($as);
        $guide = BeamUxEntry::where('slug', 'docs-guide')->sole();
        $titles = function (mixed $tree): array {
            $found = [];
            array_walk_recursive($tree, function ($value, $key) use (&$found): void {
                if ($key === 'title' || $key === 'label') {
                    $found[] = $value;
                }
            });

            return $found;
        };

        $header = in_array(self::TITLE, array_column(app(DocsChrome::class)->for($guide, $as)->toArray()['surfaces'], 'label'), true);
        $nav = in_array(self::TITLE, $titles(app(NavProjector::class)->project(BeamUxEntry::REALM_SITE, $as)->toArray()), true);
        $search = $this->getJson('/beam/docs/search?'.http_build_query(['q' => 'nothing-matches', 'root' => '/docs']));
        $searchOn = $search->status() === 200 && in_array(self::TITLE, $titles($search->json()), true);
        // The sitemap is the GUEST's view by construction (SitemapEligibility::verdict reads canList(null, …)).
        $sitemap = in_array('https://docs.test/docs/php', array_map(fn ($url) => $url->url, iterator_to_array(app(EntrySitemapSource::class)->urls(), false)), true);
        $page = $this->get('/docs/php')->getStatusCode() === 200;
        $index = $this->getJson('/beam/docs/php-api-index')->status() === 200;
        $symbol = $this->getJson('/beam/docs/php-api-symbol?'.http_build_query(['fqn' => 'Fixture\ApiA\Box']))->status() === 200;

        return ['header' => $header, 'nav' => $nav, 'search' => $searchOn, 'sitemap' => $sitemap, 'page' => $page, 'index' => $index, 'symbol' => $symbol];
    }

    private function assertOnThen(callable $break, string $why): void
    {
        $this->generate();
        $this->assertSame(self::ALL_ON, $this->surfaces(), 'a valid public artifact turns every surface on');
        $break();
        $this->assertSame(self::ALL_OFF, $this->surfaces(), $why);
    }

    public function test_a_generated_public_artifact_turns_every_surface_on_for_a_guest(): void
    {
        $this->generate();

        $this->assertSame(self::ALL_ON, $this->surfaces());
    }

    public function test_an_empty_package_list_is_the_explicit_off_state_even_with_an_artifact_on_disk(): void
    {
        $this->assertOnThen(fn () => config(['beam.docs.php_api.packages' => []]), 'an empty list is off; the leftover artifact is never served');
        $this->assertDirectoryExists($this->out, 'the artifact is still there: OFF came from config, not from a missing file');
    }

    public function test_a_missing_artifact_turns_every_surface_off(): void
    {
        $this->assertOnThen(fn () => File::deleteDirectory($this->out), 'no artifact, no surface');
    }

    public function test_a_corrupt_manifest_turns_every_surface_off(): void
    {
        $this->assertOnThen(fn () => file_put_contents($this->out.'/manifest.json', '{"schema": "beam-docs.php-api/v1", '), 'an unreadable manifest is off, never a 500');
    }

    public function test_an_artifact_for_another_audience_is_never_served(): void
    {
        // The public artifact on disk; the host turns its docs private, so the configured audience becomes `internal`.
        // A public page must not be offered as the internal reference, nor the reverse: off until regenerated.
        $this->assertOnThen(fn () => config(['beam.docs.php_api.audience' => 'internal']), 'an audience mismatch is off');
    }

    public function test_an_artifact_for_another_package_list_is_never_served(): void
    {
        $this->assertOnThen(fn () => config(['beam.docs.php_api.packages' => $this->fixtures(withApi: false)]), 'an input-digest mismatch is off');
    }

    public function test_an_artifact_with_a_foreign_schema_or_no_marker_is_never_served(): void
    {
        $this->assertOnThen(function (): void {
            $manifest = json_decode((string) file_get_contents($this->out.'/manifest.json'), true);
            $manifest['schema'] = 'beam-docs.php-api/v0';
            file_put_contents($this->out.'/manifest.json', json_encode($manifest));
        }, 'a foreign schema is off');

        $this->generate();
        $this->assertSame(self::ALL_ON, $this->surfaces());
        unlink($this->out.'/'.ArtifactWriter::MARKER);
        $this->assertSame(self::ALL_OFF, $this->surfaces(), 'a directory the writer did not produce is off');
    }

    public function test_an_artifact_with_no_published_symbols_turns_every_surface_off(): void
    {
        $this->assertOnThen(function (): void {
            // pkg-c has no @api symbol: under `public` the regenerated artifact publishes nothing.
            config(['beam.docs.php_api.packages' => $this->fixtures(withApi: false)]);
            $this->generate();
        }, 'zero published symbols is off');
    }

    public function test_private_docs_refuse_a_guest_everywhere_and_serve_an_authorized_reader(): void
    {
        config(['beam.docs.visibility' => 'private', 'beam.docs.reader_tokens' => ['auth']]);
        // `internal` follows from private visibility (GeneratePhpApiCommand::audience()).
        $this->generate();

        $this->assertSame(self::ALL_OFF, $this->surfaces(), 'a guest sees no private PHP API surface');
        $this->assertSame(array_merge(self::ALL_ON, ['sitemap' => false]), $this->surfaces(new GenericUser(['id' => 1])), 'an authorized reader sees every surface; the sitemap stays the guest\'s view');
    }

    public function test_an_authorized_reader_loses_every_surface_when_the_artifact_goes_away(): void
    {
        config(['beam.docs.visibility' => 'private', 'beam.docs.reader_tokens' => ['auth']]);
        $reader = new GenericUser(['id' => 1]);
        $this->generate();
        $this->assertSame(array_merge(self::ALL_ON, ['sitemap' => false]), $this->surfaces($reader));

        config(['beam.docs.php_api.packages' => []]);

        $this->assertSame(self::ALL_OFF, $this->surfaces($reader));
    }

    public function test_disabled_docs_turn_the_php_api_off_too(): void
    {
        $this->assertOnThen(fn () => config(['beam.docs.enabled' => false]), 'beam.docs.enabled=false is off');
    }
}
