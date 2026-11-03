<?php

namespace Splicewire\Beam\Docs\Tests\Registries;

use Splicewire\Beam\Docs\Registries\FamilyPackages;
use Splicewire\Beam\Docs\Registries\FamilyRegistryDocSources;
use Splicewire\Beam\Docs\Tests\TestCase;

/**
 * The generic `beam.docs.sources` contributor (registry-docs ticket 01, ruling 2): one DOC-4 row
 * per installed family package shipping `docs/registries/*.mdx` — package-stamped, idempotent by
 * path, data files ignored — and no row at all when the docs seed chain could not materialize it.
 */
class FamilyRegistryDocSourcesTest extends TestCase
{
    private function withPackages(array $packages): FamilyRegistryDocSources
    {
        $fake = new class($packages) extends FamilyPackages
        {
            public function __construct(private array $packages) {}

            public function installed(): array
            {
                return $this->packages;
            }
        };

        return new FamilyRegistryDocSources($fake);
    }

    public function test_a_package_shipping_registry_pages_contributes_one_doc4_row(): void
    {
        $alpha = dirname(__DIR__).'/Fixtures/RegistryDocs/packages/alpha';
        $contributor = $this->withPackages(['splicewire/fixture-alpha' => $alpha]);

        $contributor->contribute();
        $sources = config('beam.docs.sources');

        $this->assertCount(1, $sources);
        $this->assertSame([
            'path' => $alpha.'/docs/registries',
            'under' => '/docs/registries',
            'type' => 'page',
            'product' => 'splicewire',
            'package' => 'splicewire/fixture-alpha',
            'ignore' => ['manifest.json', 'catalog.json'],
        ], $sources[0]);
    }

    public function test_the_row_is_idempotent_by_path_across_re_boots(): void
    {
        $alpha = dirname(__DIR__).'/Fixtures/RegistryDocs/packages/alpha';
        $contributor = $this->withPackages(['splicewire/fixture-alpha' => $alpha]);

        $contributor->contribute();
        $contributor->contribute();

        $this->assertCount(1, config('beam.docs.sources'));
    }

    public function test_a_package_without_registry_pages_contributes_nothing(): void
    {
        // beta declares a root but ships no docs/registries at all; a manifest-only package (no
        // .mdx beside its manifest) is the same case — nothing to mount.
        $beta = dirname(__DIR__).'/Fixtures/RegistryDocs/packages/beta';
        $this->withPackages(['splicewire/fixture-beta' => $beta])->contribute();

        $this->assertSame([], (array) config('beam.docs.sources'));
    }

    public function test_no_row_is_contributed_where_the_seed_chain_could_not_materialize_it(): void
    {
        config(['beam.docs.seed' => false]);
        $alpha = dirname(__DIR__).'/Fixtures/RegistryDocs/packages/alpha';

        $this->withPackages(['splicewire/fixture-alpha' => $alpha])->contribute();

        $this->assertSame([], (array) config('beam.docs.sources'));
    }

    public function test_a_host_declared_row_for_the_same_directory_is_left_alone(): void
    {
        $alpha = dirname(__DIR__).'/Fixtures/RegistryDocs/packages/alpha';
        $existing = ['path' => $alpha.'/docs/registries', 'under' => null, 'type' => 'page', 'product' => 'splicewire'];
        config(['beam.docs.sources' => [$existing]]);

        $this->withPackages(['splicewire/fixture-alpha' => $alpha])->contribute();

        $this->assertSame([$existing], config('beam.docs.sources'));
    }
}
