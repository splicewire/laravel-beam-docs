<?php

namespace Splicewire\Beam\Docs\Tests\Editor;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Splicewire\Beam\Docs\DocsUxServiceProvider;
use Splicewire\Beam\Docs\Editor\ApiReferenceNodeSource;
use Splicewire\Beam\Ux\Manifest\ManifestProfileResolver;

/**
 * EP-42 S1 (ruling R1): this package's docs page places `<ApiReference specUrl="…" />`, rendered by
 * `@splicewire/beam-docs`, so this package declares it as a Code Component an MDX page holding blocks may place. It
 * joins only that page's profile (laravel-beam-ux's MDX_SOURCES), not a TSX page's or the canvas registry's.
 */
class ApiReferenceNodeSourceTest extends TestCase
{
    public function test_it_declares_api_reference_with_its_spec_url(): void
    {
        $nodes = (new ApiReferenceNodeSource)->manifest('content')['nodes'];

        $this->assertSame(['ApiReference'], array_column($nodes, 'name'));
        $this->assertSame('string', $nodes[0]['attrsSchema']['properties']['specUrl']['type']);
        $this->assertSame(['specUrl'], $nodes[0]['attrsSchema']['required']);
        $this->assertSame([], (new ApiReferenceNodeSource)->nodeClassesFor('chrome'));
    }

    public function test_the_provider_offers_it_to_mdx_pages_alone(): void
    {
        if (! defined(ManifestProfileResolver::class.'::MDX_SOURCES')) {
            $this->markTestSkipped('laravel-beam-ux predates MDX_SOURCES (EP-42 S1)');
        }
        $app = new Container;

        (new DocsUxServiceProvider($app))->register();

        $this->assertSame([ApiReferenceNodeSource::class], array_map('get_class', iterator_to_array($app->tagged(ManifestProfileResolver::MDX_SOURCES), false)));
        $this->assertSame([], iterator_to_array($app->tagged(ManifestProfileResolver::PACKAGE_SOURCES), false));
    }
}
