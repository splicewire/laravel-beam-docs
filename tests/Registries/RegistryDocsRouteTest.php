<?php

namespace Splicewire\Beam\Docs\Tests\Registries;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * The registry deep link `beam/docs/registry/{root}` (registry-docs ticket 01, amended ruling 1):
 * the dotted root travels VERBATIM in the URL (no slugifier, ruling 3 / R11); a locally served page
 * or a catalog external pointer redirects; a catalogued root this host cannot link answers 200
 * with the availability annotation — shown, not hidden; only a root nothing knows is a 404.
 */
class RegistryDocsRouteTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();

        $this->dir = sys_get_temp_dir().'/registry-docs-route-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir.'/catalog.json', json_encode(['version' => 1, 'roots' => [
            'fixture.elsewhere' => ['owner' => 'splicewire/fixture-alpha', 'doc' => ['kind' => 'external', 'url' => 'https://example.com/docs#elsewhere']],
            'fixture.not-here' => ['owner' => 'splicewire/fixture-beta', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/fixture.not-here.mdx', 'url' => '/docs/registries/fixture.not-here']],
            'fixture.exempted' => ['owner' => 'splicewire/fixture-alpha', 'doc' => ['kind' => 'exempt', 'reason' => 'infrastructure: the index documents itself on the foundations page', 'pointer' => 'docs/foundations/registries.md']],
        ]]));
        config(['beam.docs.registries.catalog' => $this->dir.'/catalog.json']);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_a_served_page_redirects_with_the_dotted_root_verbatim_in_the_url(): void
    {
        $site = BeamUxEntry::rootFor();
        $docs = BeamUxEntry::create(['slug' => 'docs', 'title' => 'Docs', 'type' => 'page', 'segment' => '/docs', 'parent_id' => $site->getKey()]);
        $registries = BeamUxEntry::create(['slug' => 'docs-registries', 'title' => 'Registries', 'type' => 'page', 'segment' => 'registries', 'parent_id' => $docs->getKey()]);
        BeamUxEntry::create(['slug' => 'fixture.alpha', 'title' => 'fixture.alpha', 'type' => 'page', 'segment' => 'fixture.alpha', 'parent_id' => $registries->getKey()]);

        $this->get('beam/docs/registry/fixture.alpha')
            ->assertRedirect('/docs/registries/fixture.alpha');
    }

    public function test_a_catalog_external_pointer_redirects(): void
    {
        $this->get('beam/docs/registry/fixture.elsewhere')
            ->assertRedirect('https://example.com/docs#elsewhere');
    }

    public function test_a_catalogued_root_this_host_cannot_link_is_shown_not_hidden(): void
    {
        $response = $this->get('beam/docs/registry/fixture.not-here');

        $response->assertOk();
        $response->assertJson(['root' => 'fixture.not-here', 'available' => false, 'kind' => 'page']);
        $this->assertSame('not available on this host', $response->json('note'));
        $this->assertFalse($response->json('describedHere'));
    }

    public function test_an_exempt_root_answers_with_its_reason_and_pointer(): void
    {
        $response = $this->get('beam/docs/registry/fixture.exempted');

        $response->assertOk();
        $response->assertJson([
            'root' => 'fixture.exempted',
            'available' => false,
            'kind' => 'exempt',
            'reason' => 'infrastructure: the index documents itself on the foundations page',
            'pointer' => 'docs/foundations/registries.md',
        ]);
    }

    public function test_a_root_nothing_knows_is_a_404(): void
    {
        $this->get('beam/docs/registry/fixture.nowhere')->assertNotFound();
    }

    public function test_the_deep_link_never_claims_a_public_page_path_on_a_beam_docs_segment_host(): void
    {
        // The www spine serves its docs at `/beam/docs`, so its registry pages live at
        // `/beam/docs/registries/<root>`. A package route at that path would take every page from the
        // host catch-all (ADR-0209 §2) and redirect each one to itself.
        $route = app('router')->getRoutes()->getByName('beam.docs.registries.show');

        $this->assertSame('beam/docs/registry/{root}', $route->uri());
        $this->assertFalse($route->matches(Request::create('/beam/docs/registries/beam.particle')));
    }
}
