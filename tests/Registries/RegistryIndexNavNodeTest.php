<?php

namespace Splicewire\Beam\Docs\Tests\Registries;

use Illuminate\Filesystem\Filesystem;
use Rushing\DataNav\NavKindRegistry;
use Rushing\DataNav\NavLink;
use Rushing\DataNav\NavTree;
use Splicewire\Beam\Docs\Registries\RegistryDocsAvailability;
use Splicewire\Beam\Docs\Registries\RegistryDocsNav;
use Splicewire\Beam\Docs\Registries\RegistryIndexNavNode;
use Splicewire\Beam\Docs\Tests\TestCase;

/**
 * docs-feedback ticket 07: with `beam.docs.registries.nav.widget` = `index`, the registry section is
 * the lead pages followed by ONE `beam-docs/registry-index` node. Its `index` carries the same
 * groups the `links` mode draws, with availability as a value; its children ARE the `links` list,
 * so a client that does not know the kind still draws a list.
 */
class RegistryIndexNavNodeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/registry-index-nav-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
        file_put_contents($this->dir.'/catalog.json', json_encode([
            'version' => 1,
            'roots' => [
                'beam.workflows.definitions' => ['owner' => 'splicewire/laravel-beam-workflows', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/beam.workflows.definitions.mdx', 'url' => '/beam/docs/registries/beam.workflows.definitions']],
                'workflow' => ['owner' => 'splicewire/laravel-beam-workflows', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/workflow.mdx', 'url' => '/beam/docs/registries/workflow']],
                'conduit' => ['owner' => 'splicewire/tower', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/conduit.mdx', 'url' => '/beam/docs/registries/conduit']],
                'determination.corpus.streams' => ['owner' => 'splicewire/tower', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/determination.corpus.streams.mdx', 'url' => '/beam/docs/registries/determination.corpus.streams']],
                'determination.disclosure.subjects' => ['owner' => 'splicewire/tower', 'doc' => ['kind' => 'page', 'path' => 'docs/registries/determination.disclosure.subjects.mdx', 'url' => '/beam/docs/registries/determination.disclosure.subjects']],
                'beam.doctor.facade-scope' => ['owner' => 'splicewire/laravel-beam', 'doc' => ['kind' => 'exempt', 'class' => 'rebind-only', 'reason' => 'rebind only', 'pointer' => 'docs/registries/beam.doctor.mdx']],
                'beam.undocumented' => ['owner' => 'splicewire/laravel-beam', 'doc' => null],
                'popcorn.invocables' => ['owner' => 'rushing/php-popcorn', 'doc' => ['kind' => 'external', 'url' => 'https://example.com/popcorn']],
            ],
            'clusters' => [
                'beam.workflows' => ['owner' => 'splicewire/laravel-beam-workflows', 'path' => 'docs/registries/beam.workflows.mdx', 'url' => '/beam/docs/registries/beam.workflows', 'title' => 'Workflow registries'],
                'determination' => ['owner' => 'splicewire/tower', 'path' => 'docs/registries/determination.mdx', 'url' => '/beam/docs/registries/determination', 'title' => 'Determination registries'],
                'determination.corpus' => ['owner' => 'splicewire/tower', 'path' => 'docs/registries/determination.corpus.mdx', 'url' => '/beam/docs/registries/determination.corpus', 'title' => 'Corpus'],
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
                'widget' => 'index',
            ],
        ]);

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

    public function test_the_index_mode_emits_the_lead_then_one_registry_index_node(): void
    {
        $section = $this->section(app(RegistryDocsNav::class)->compose($this->projected()));

        $this->assertSame(['Foundations', 'Registry index'], array_column($section, 'title'));
        $this->assertSame('nav/link', $section[0]['kind'], 'the lead pages stay ordinary links');
        $this->assertSame(RegistryIndexNavNode::KIND, $section[1]['kind']);
        $this->assertNull($section[1]['href'], 'the index node is not a page, so the docs rail never re-roots on it');
    }

    public function test_the_index_carries_the_links_groups_with_availability_as_a_value(): void
    {
        $index = $this->section(app(RegistryDocsNav::class)->compose($this->projected()))[1]['index'];

        $this->assertSame(8, $index['total'], 'every catalog root is counted');
        $this->assertSame(['Tower operations', 'beam', 'Workflow registries', 'Popcorn', 'On this host'], array_column($index['groups'], 'title'));

        [$tower, $beam, $workflows, $popcorn, $host] = $index['groups'];
        $this->assertSame([], $tower['roots'], 'a configured heading holds groups, not roots');
        $this->assertSame(['conduit', 'determination'], array_column($tower['groups'], 'key'));

        $conduit = $tower['groups'][0]['roots'][0];
        $this->assertSame(['root' => 'conduit', 'href' => '/beam/docs/registry/conduit', 'availability' => 'not-available', 'note' => 'not available on this host'], $conduit);

        $determination = $tower['groups'][1];
        $this->assertSame(['determination.disclosure.subjects'], array_column($determination['roots'], 'root'));
        $this->assertSame(['determination.corpus'], array_column($determination['groups'], 'key'), 'a cluster inside a cluster nests in its groups');
        $this->assertSame(['determination.corpus.streams'], array_column($determination['groups'][0]['roots'], 'root'));

        $this->assertSame(
            ['beam.doctor.facade-scope' => 'exempt', 'beam.undocumented' => 'not-documented'],
            array_column($beam['roots'], 'availability', 'root'),
        );
        $this->assertSame('no page: rebind-only', $beam['roots'][0]['note']);

        $this->assertSame('/beam/docs/registries/beam.workflows', $workflows['href']);
        $this->assertSame(['served', 'served'], array_column($workflows['roots'], 'availability'));
        $this->assertSame(['root' => 'popcorn.invocables', 'href' => 'https://example.com/popcorn', 'availability' => 'external', 'note' => null], $popcorn['roots'][0]);
        $this->assertSame([['root' => 'calendar', 'href' => '/beam/docs/registries/calendar', 'availability' => 'served', 'note' => null]], $host['roots'], 'an app-local page the catalog does not list is kept');
    }

    public function test_the_index_nodes_children_are_exactly_the_links_mode_section(): void
    {
        $index = $this->section(app(RegistryDocsNav::class)->compose($this->projected()));

        config(['beam.docs.registries.nav.widget' => 'links']);
        $links = $this->section(app(RegistryDocsNav::class)->compose($this->projected()));

        $this->assertSame(array_slice($links, 1), $index[1]['children'], 'a client that does not know the kind draws the links list');
        $this->assertSame($links[0], $index[0]);
    }

    public function test_an_unknown_widget_value_reads_as_links(): void
    {
        config(['beam.docs.registries.nav.widget' => 'carousel']);

        $this->assertSame(RegistryDocsNav::WIDGET_LINKS, app(RegistryDocsNav::class)->widget());
        $this->assertNotContains(RegistryIndexNavNode::KIND, array_column($this->section(app(RegistryDocsNav::class)->compose($this->projected())), 'kind'));
    }

    public function test_the_kind_is_registered_and_survives_the_round_trip(): void
    {
        $this->assertSame(RegistryIndexNavNode::class, app(NavKindRegistry::class)->classFor(RegistryIndexNavNode::KIND));

        $tree = app(RegistryDocsNav::class)->compose($this->projected());
        $again = NavTree::from($tree->toArray());

        $node = $again->items[0]->children[1]->children[1];
        $this->assertInstanceOf(RegistryIndexNavNode::class, $node);
        $this->assertSame($tree->toArray(), $again->toArray());
    }
}
