<?php

namespace Splicewire\Beam\Docs\Tests\Chrome;

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Docs\Chrome\DocsChrome;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Ux\Http\EntryRenderer;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs-walkthrough DOCS-12 (DM4, DOC-7, C-1, C-6; lead rulings 07:17Z): every docs page's header data comes from ONE
 * server read, `DocsChrome::for()`. The brand is the docs root's declared product (`beam.brands[product]`, else
 * `beam.brand`); "back" is that brand's `home`, else `/`; the surfaces are the root's readable `SpreadTemplate` children,
 * labelled by their row titles; `related` is every other declared product, with its tagline.
 */
class DocsChromeTest extends TestCase
{
    private BeamUxEntry $docs;

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();

        $site = BeamUxEntry::rootFor();
        $this->docs = $this->page('docs', 'Docs', ['segment' => '/docs', 'parent_id' => $site->getKey(), 'layout' => 'DocsLayout']);
        $this->page('docs-api', 'API Reference', ['segment' => 'api', 'parent_id' => $this->docs->getKey(), 'template' => 'SpreadTemplate']);
        $this->page('docs-mcp', 'MCP Server', ['segment' => 'mcp', 'parent_id' => $this->docs->getKey(), 'template' => 'SpreadTemplate']);
        $this->page('docs-guide', 'A guide', ['segment' => 'guide', 'parent_id' => $this->docs->getKey()]);
        $this->page('docs-draft', 'Draft surface', ['segment' => 'draft', 'parent_id' => $this->docs->getKey(), 'template' => 'SpreadTemplate', 'access' => []]);

        config([
            'beam.brand' => ['name' => 'Install'],
            'beam.docs.sources' => [['path' => 'docs', 'under' => null, 'type' => 'page', 'product' => 'splicewire']],
            'beam.brands' => [
                'splicewire' => ['name' => 'Splicewire', 'home' => 'https://splicewire.test/', 'tagline' => 'Run your stack'],
                'beam' => ['name' => 'Beam', 'home' => 'https://splicewire.test/beam', 'tagline' => 'Build your own app on Beam', 'docs' => 'https://splicewire.test/beam/docs'],
            ],
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function page(string $slug, string $title, array $attributes): BeamUxEntry
    {
        return BeamUxEntry::create(['slug' => $slug, 'title' => $title, 'type' => 'page', ...$attributes]);
    }

    private function chrome(string $slug): array
    {
        $entry = BeamUxEntry::where('slug', $slug)->sole();

        return app(DocsChrome::class)->for($entry, null)->toArray();
    }

    public function test_the_brand_is_the_declared_product_and_back_is_its_home(): void
    {
        $chrome = $this->chrome('docs-guide');

        $this->assertSame('Splicewire', $chrome['brand']['name']);
        $this->assertSame('https://splicewire.test/', $chrome['back']);
        $this->assertSame('/docs', $chrome['home']);
    }

    public function test_an_undeclared_product_reads_the_install_brand_and_goes_back_to_the_site_root(): void
    {
        config(['beam.docs.sources' => [], 'beam.brands' => []]);

        $chrome = $this->chrome('docs');

        $this->assertSame('Install', $chrome['brand']['name']);
        $this->assertSame('/', $chrome['back']);
        $this->assertSame([], $chrome['related']);
    }

    public function test_the_surfaces_are_the_roots_readable_spread_children_labelled_by_their_titles(): void
    {
        $this->assertSame(
            [['label' => 'API Reference', 'href' => '/docs/api'], ['label' => 'MCP Server', 'href' => '/docs/mcp']],
            $this->chrome('docs-guide')['surfaces'],
        );

        // A surface label IS its row title (UX IA-11): an edit to the row is the edit to the header.
        BeamUxEntry::where('slug', 'docs-api')->update(['title' => 'API']);
        $this->assertSame('API', $this->chrome('docs-guide')['surfaces'][0]['label']);
    }

    public function test_related_is_every_other_declared_product_with_its_tagline(): void
    {
        $this->assertSame(
            [['key' => 'beam', 'name' => 'Beam', 'tagline' => 'Build your own app on Beam', 'href' => 'https://splicewire.test/beam', 'docs' => 'https://splicewire.test/beam/docs']],
            $this->chrome('docs-guide')['related'],
        );
    }

    public function test_a_docs_page_carries_the_chrome_and_a_page_outside_docs_does_not(): void
    {
        $this->app->bind(EntryRenderer::class, fn () => new class implements EntryRenderer
        {
            public function render(string $page, array $props): Response
            {
                return new JsonResponse(['page' => $page, 'props' => $props]);
            }
        });
        $this->page('about', 'About', ['segment' => 'about', 'parent_id' => BeamUxEntry::rootFor()->getKey()]);
        Route::beamUxSite('site/entry');

        $this->assertSame('Splicewire', $this->get('/docs/guide')->json('props.docsChrome.brand.name'));
        $this->assertNull($this->get('/about')->json('props.docsChrome'));
    }
}
