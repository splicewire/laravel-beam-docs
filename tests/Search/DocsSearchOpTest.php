<?php

namespace Splicewire\Beam\Docs\Tests\Search;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Rushing\Versioning\Contracts\VersionStore;
use Rushing\Versioning\Models\Version;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * docs-walkthrough DOCS-14 (DM6, DOC-9; lead rulings 08:04Z): `GET beam/docs/search?q=&root=` returns the root's
 * published, readable, LISTABLE entries for this principal, best first. The index is cached UNGATED; gating runs on every
 * request, so two principals hitting one warm cache each see only their own rows.
 */
class DocsSearchOpTest extends TestCase
{
    /** @var array<string, mixed> particle key => working (HEAD) body */
    private array $bodies = [];

    private BeamUxEntry $docs;

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        Cache::flush();

        $driver = Mockery::mock(StorageDriver::class);
        $driver->shouldReceive('read')->andReturnUsing(fn (string $key) => array_key_exists($key, $this->bodies)
            ? new StorageItem($key, $this->bodies[$key], null, time()) : null);
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $driver));

        $site = BeamUxEntry::rootFor();
        $this->docs = $this->page('docs', 'Docs', ['segment' => '/docs', 'parent_id' => $site->getKey(), 'layout' => 'DocsLayout']);
        $build = $this->page('docs-build', 'Build', ['segment' => 'build', 'parent_id' => $this->docs->getKey()]);
        $this->page('install-extension', 'Install a paid extension', ['segment' => 'install-extension', 'parent_id' => $build->getKey()], 'Buy it, then install it from the market.');
        $this->page('webhooks', 'Webhooks', ['segment' => 'webhooks', 'parent_id' => $build->getKey()], "# Signing\nEvery delivery is signed. To install a hook, register its URL.");
        $this->page('docs-api', 'API Reference', ['segment' => 'api', 'parent_id' => $this->docs->getKey(), 'template' => 'SpreadTemplate']);
        $this->page('about', 'About the install', ['segment' => 'about', 'parent_id' => $site->getKey()], 'install install install');

        config([
            'beam.docs.sources' => [['path' => 'docs', 'under' => null, 'type' => 'page', 'product' => 'splicewire']],
            'beam.brands' => ['beam' => ['name' => 'Beam', 'home' => '/beam', 'docs' => '/beam/docs']],
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function page(string $slug, string $title, array $attributes, ?string $content = null): BeamUxEntry
    {
        $particle = $content === null ? null : 'p-'.$slug;
        if ($particle !== null) {
            $this->bodies[$particle] = ['frontmatter' => [], 'content' => $content];
        }

        return BeamUxEntry::create(['slug' => $slug, 'title' => $title, 'type' => 'page', 'format' => 'mdx', 'particle_id' => $particle, ...$attributes]);
    }

    private function search(string $q, ?Authenticatable $as = null): array
    {
        $as === null ? $this->app['auth']->guard()->logout() : $this->actingAs($as);

        return $this->getJson('/beam/docs/search?'.http_build_query(['q' => $q, 'root' => '/docs']))->assertOk()->json();
    }

    private function titles(array $payload, string $kind = 'page'): array
    {
        return array_values(array_map(fn ($r) => $r['title'], array_filter($payload['results'] ?? $payload['data']['results'] ?? [], fn ($r) => $r['kind'] === $kind)));
    }

    public function test_a_guest_finds_published_docs_title_first_and_only_under_the_root(): void
    {
        $payload = $this->search('install');

        $this->assertSame(['Install a paid extension', 'Webhooks'], $this->titles($payload), 'a title hit outranks a body hit; the site page outside the root is never offered');
    }

    public function test_the_readable_api_reference_surface_is_always_offered(): void
    {
        $this->assertSame(['API Reference'], $this->titles($this->search('install'), 'reference'));
        $this->assertSame(['API Reference'], $this->titles($this->search('nothing-matches-this'), 'reference'));
    }

    public function test_a_miss_offers_the_related_products_docs_with_the_same_query(): void
    {
        $payload = $this->search('quasar');
        $fallback = $payload['fallback'] ?? $payload['data']['fallback'] ?? null;

        $this->assertSame(['label' => 'Search Beam docs', 'href' => '/beam/docs?q=quasar'], $fallback);
    }

    public function test_a_page_nav_hides_is_never_returned(): void
    {
        // Open to read (access) but not to list (traverse denied): canRender lets it through, canList does not.
        BeamUxEntry::where('slug', 'webhooks')->update(['traverse' => json_encode(['nobody'])]);

        $this->assertSame(['Install a paid extension'], $this->titles($this->search('install')));
    }

    public function test_an_unpublished_drafts_text_is_never_searchable(): void
    {
        // Published at a pinned version whose body says "signed"; the working HEAD has since gained draft text.
        BeamUxEntry::where('slug', 'webhooks')->update(['published_version' => 'v1']);
        $this->bodies['p-webhooks'] = ['frontmatter' => [], 'content' => 'Every delivery is signed. SECRETDRAFTWORD'];
        $pinned = (new Version)->forceFill(['snapshot' => ['payload' => ['frontmatter' => [], 'content' => 'Every delivery is signed.']]]);
        $versions = Mockery::mock(VersionStore::class);
        $versions->shouldReceive('version')->andReturnUsing(fn ($particle, string $ref) => $ref === 'v1' ? $pinned : null);
        $this->app->instance(VersionStore::class, $versions);

        $this->assertSame([], $this->titles($this->search('secretdraftword')));
        $this->assertSame(['Webhooks'], $this->titles($this->search('signed')));
    }

    public function test_two_principals_with_different_access_share_a_warm_cache_without_sharing_rows(): void
    {
        BeamUxEntry::where('slug', 'webhooks')->update(['access' => json_encode(['auth'])]);
        $member = (new User)->forceFill(['id' => 7]);

        // Warm the cache as the principal who CAN read the gated page, then ask as a guest, then again as the member.
        $this->assertSame(['Install a paid extension', 'Webhooks'], $this->titles($this->search('install', $member)));
        $this->assertSame(['Install a paid extension'], $this->titles($this->search('install')));
        $this->assertSame(['Install a paid extension', 'Webhooks'], $this->titles($this->search('install', $member)));
    }

    public function test_a_root_that_is_not_a_docs_root_is_the_uniform_404(): void
    {
        $this->getJson('/beam/docs/search?'.http_build_query(['q' => 'install', 'root' => '/about']))->assertNotFound();
    }

    public function test_private_docs_refuse_a_guest_at_the_door(): void
    {
        config(['beam.docs.visibility' => 'private']);

        $this->getJson('/beam/docs/search?'.http_build_query(['q' => 'install', 'root' => '/docs']))->assertNotFound();
    }
}
