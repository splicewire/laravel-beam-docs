<?php

namespace Splicewire\Beam\Docs\Tests\Registries;

use Illuminate\Filesystem\Filesystem;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Docs\Registries\RegistryDocsAvailability;
use Splicewire\Beam\Docs\Registries\RegistryDocsCatalog;
use Splicewire\Beam\Docs\Registries\RegistryDocsNav;
use Splicewire\Beam\Docs\Registries\RegistryDocsNavProjector;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Ux\Containment\NavProjector;

/**
 * The registry section of the `site` nav (registry-docs ticket 01, ruling 7; ticket 08): the
 * committed catalog grouped by cluster, annotated with this host's availability, composed into
 * the projector's already-gated tree. A root this host does not serve is shown, never hidden.
 */
class RegistryDocsNavTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/registry-docs-nav-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir.'/catalog.json', json_encode([
            'version' => 1,
            'roots' => [
                'beam.workflows.definitions' => ['owner' => 'splicewire/laravel-beam-workflows', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/beam.workflows.definitions.mdx', 'url' => '/beam/docs/registries/beam.workflows.definitions']],
                'workflow' => ['owner' => 'splicewire/laravel-beam-workflows', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/workflow.mdx', 'url' => '/beam/docs/registries/workflow']],
                'conduit' => ['owner' => 'splicewire/tower', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/conduit.mdx', 'url' => '/beam/docs/registries/conduit']],
                'determination.corpus.streams' => ['owner' => 'splicewire/tower', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/determination.corpus.streams.mdx', 'url' => '/beam/docs/registries/determination.corpus.streams']],
                'beam.doctor.facade-scope' => ['owner' => 'splicewire/laravel-beam', 'doc' => ['kind' => 'exempt', 'class' => 'rebind-only', 'reason' => 'rebind only', 'pointer' => 'docs/registries/beam.doctor.mdx']],
                'popcorn.invocables' => ['owner' => 'rushing/php-popcorn', 'doc' => ['kind' => 'external', 'url' => 'https://example.com/popcorn']],
                'determination.disclosure.subjects' => ['owner' => 'splicewire/tower', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/determination.disclosure.subjects.mdx', 'url' => '/beam/docs/registries/determination.disclosure.subjects']],
                'determination.corpus.streams.nested' => ['owner' => 'splicewire/tower', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/determination.corpus.streams.nested.mdx', 'url' => '/beam/docs/registries/determination.corpus.streams.nested']],
            ],
            'clusters' => [
                'beam.workflows' => ['owner' => 'splicewire/laravel-beam-workflows', 'path' => 'docs/registries/beam.workflows.mdx', 'url' => '/beam/docs/registries/beam.workflows', 'title' => 'Workflow registries'],
                'determination' => ['owner' => 'splicewire/tower', 'path' => 'docs/registries/determination.mdx', 'url' => '/beam/docs/registries/determination', 'title' => 'Determination registries'],
                'determination.corpus.streams' => ['owner' => 'splicewire/tower', 'path' => 'docs/registries/determination.corpus.streams.mdx', 'url' => '/beam/docs/registries/determination.corpus.streams', 'title' => 'Corpus streams'],
            ],
        ]));

        config([
            'beam.docs.segment' => '/beam/docs',
            'beam.docs.registries.catalog' => $this->dir.'/catalog.json',
            'beam.docs.registries.nav' => [
                'groupings' => ['Tower operations' => ['conduit', 'determination']],
                'place' => ['workflow' => 'beam.workflows'],
                'lead' => ['/beam/docs/foundations'],
                'titles' => ['popcorn' => 'Popcorn'],
            ],
        ]);

        // This host runs the workflows package only: tower's roots are not described here.
        $this->app->instance(RegistryDocsAvailability::class, new class extends RegistryDocsAvailability
        {
            public function knownLocally(string $root): ?bool
            {
                return str_starts_with($root, 'beam.') || $root === 'workflow';
            }
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    /** What the projector hands over on such a host: the served (gated) pages, flat under the anchor. */
    private function projected(): NavTree
    {
        return NavTree::make([
            NavLink::make('Beam documentation', '/beam/docs', children: [
                NavLink::make('Foundations', '/beam/docs/foundations', children: [NavLink::make('Layers', '/beam/docs/foundations/layers')]),
                NavLink::make('Registries', '/beam/docs/registries', children: [
                    NavLink::make('beam.workflows.definitions', '/beam/docs/registries/beam.workflows.definitions'),
                    NavLink::make('workflow', '/beam/docs/registries/workflow'),
                    NavLink::make('Workflow registries', '/beam/docs/registries/beam.workflows'),
                    NavLink::make('calendar', '/beam/docs/registries/calendar'),
                ]),
            ]),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function section(NavTree $tree): array
    {
        return $tree->toArray()['items'][0]['children'][1]['children'];
    }

    public function test_the_section_is_the_catalog_grouped_by_cluster_with_availability_shown_not_hidden(): void
    {
        $section = $this->section(app(RegistryDocsNav::class)->compose($this->projected()));
        $titles = array_column($section, 'title');

        $this->assertSame(['Layers'], array_column($section[0]['children'], 'title'), 'the lead section (foundations) comes first');
        $this->assertSame(['Foundations', 'Tower operations', 'beam', 'Workflow registries', 'Popcorn', 'calendar'], $titles);

        [$conduit, $determination] = $section[1]['children'];
        $this->assertSame('conduit', $conduit['title']);
        $this->assertSame('conduit (not available on this host)', $conduit['children'][0]['title']);
        $this->assertSame('/beam/docs/registry/conduit', $conduit['children'][0]['href'], 'an unserved root links to the deep link, which answers the annotation');
        $this->assertSame('Determination registries', $determination['title'], 'an unserved cluster is named from the catalog');
        $this->assertNull($determination['href']);
        $this->assertSame(['determination.disclosure.subjects (not available on this host)', 'Corpus streams'], array_column($determination['children'], 'title'), 'a cluster inside a cluster nests under it, after the parent\'s own roots');
        $this->assertSame(['determination.corpus.streams (not available on this host)', 'determination.corpus.streams.nested (not available on this host)'], array_column($determination['children'][1]['children'], 'title'));

        $workflows = $section[3];
        $this->assertSame('/beam/docs/registries/beam.workflows', $workflows['href'], 'a served cluster page heads its group');
        $this->assertSame(['beam.workflows.definitions', 'workflow'], array_column($workflows['children'], 'title'), '`workflow` is placed with the beam.workflows cluster');
        $this->assertSame('/beam/docs/registries/workflow', $workflows['children'][1]['href']);

        $this->assertSame('beam.doctor.facade-scope (no page: rebind-only)', $section[2]['children'][0]['title']);
        $this->assertSame('https://example.com/popcorn', $section[4]['children'][0]['href'], 'an external pointer links out');
        $this->assertSame('/beam/docs/registries/calendar', $section[5]['href'], 'a served page the catalog does not list (app-local) is kept');
    }

    public function test_without_a_catalog_the_projection_is_returned_unchanged(): void
    {
        config(['beam.docs.registries.catalog' => $this->dir.'/absent.json']);
        app(RegistryDocsCatalog::class)->fresh();
        $tree = $this->projected();

        $this->assertSame($tree->toArray(), app(RegistryDocsNav::class)->compose($tree)->toArray());
    }

    public function test_composition_never_mutates_the_projected_tree(): void
    {
        $tree = $this->projected();
        $before = $tree->toArray();

        app(RegistryDocsNav::class)->compose($tree);

        $this->assertSame($before, $tree->toArray());
    }

    public function test_the_projector_is_decorated_so_the_site_nav_carries_the_composition(): void
    {
        $this->assertInstanceOf(RegistryDocsNavProjector::class, app(NavProjector::class));
    }
}
