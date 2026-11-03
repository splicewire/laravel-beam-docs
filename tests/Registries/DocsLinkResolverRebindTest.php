<?php

namespace Splicewire\Beam\Docs\Tests\Registries;

use Illuminate\Filesystem\Filesystem;
use Splicewire\Beam\Docs\Registries\BeamDocsLinkResolver;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Ux\Docs\DocsLinkResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * beam-docs' rebind of beam-ux's null-safe DocsLinkResolver seam (registry-docs ticket 01, amended
 * ruling 1): installing beam-docs supersedes the null default; the resolver reads the entries table
 * first and the committed spine catalog second, answers null for everything else — the empty root
 * structurally, exemptions as "no link" — and never throws, migrated tables or not.
 */
class DocsLinkResolverRebindTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/docs-link-resolver-'.bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function catalog(array $roots): void
    {
        file_put_contents($this->dir.'/catalog.json', json_encode(['version' => 1, 'roots' => $roots]));
        config(['beam.docs.registries.catalog' => $this->dir.'/catalog.json']);
    }

    public function test_installing_beam_docs_supersedes_the_null_default(): void
    {
        $this->assertInstanceOf(BeamDocsLinkResolver::class, $this->app->make(DocsLinkResolver::class));
    }

    public function test_the_seam_never_throws_without_the_entries_table_and_answers_null(): void
    {
        // No beam_ux_entries migration ran here — the null path IS the contract.
        $resolver = $this->app->make(DocsLinkResolver::class);

        $this->assertNull($resolver->urlFor('fixture.unknown'));
        $this->assertNull($resolver->urlFor(''));
    }

    public function test_a_catalog_external_pointer_resolves_and_an_exemption_stays_a_null_link(): void
    {
        $this->catalog([
            'fixture.elsewhere' => ['owner' => 'splicewire/fixture-alpha', 'doc' => ['kind' => 'external', 'url' => 'https://example.com/docs#elsewhere']],
            'fixture.exempted' => ['owner' => 'splicewire/fixture-alpha', 'doc' => ['kind' => 'exempt', 'reason' => 'infrastructure', 'pointer' => 'docs/why.md']],
            'fixture.uncovered' => ['owner' => 'splicewire/fixture-beta', 'doc' => null],
        ]);

        $resolver = $this->app->make(DocsLinkResolver::class);

        $this->assertSame('https://example.com/docs#elsewhere', $resolver->urlFor('fixture.elsewhere'));
        $this->assertNull($resolver->urlFor('fixture.exempted'), 'exempt means documented elsewhere in prose — no link, not a guess');
        $this->assertNull($resolver->urlFor('fixture.uncovered'));
    }

    public function test_a_served_page_in_the_entries_table_wins_over_the_catalog(): void
    {
        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();

        $site = BeamUxEntry::rootFor();
        $docs = BeamUxEntry::create(['slug' => 'docs', 'title' => 'Docs', 'type' => 'page', 'segment' => '/docs', 'parent_id' => $site->getKey()]);
        $registries = BeamUxEntry::create(['slug' => 'docs-registries', 'title' => 'Registries', 'type' => 'page', 'segment' => 'registries', 'parent_id' => $docs->getKey()]);
        BeamUxEntry::create(['slug' => 'fixture.alpha', 'title' => 'fixture.alpha', 'type' => 'page', 'segment' => 'fixture.alpha', 'parent_id' => $registries->getKey()]);

        $this->catalog([
            'fixture.alpha' => ['owner' => 'splicewire/fixture-alpha', 'doc' => ['kind' => 'external', 'url' => 'https://example.com/should-not-win']],
        ]);

        $this->assertSame('/docs/registries/fixture.alpha', $this->app->make(DocsLinkResolver::class)->urlFor('fixture.alpha'));
    }
}
