<?php

namespace Splicewire\Beam\Docs\Tests\Doctor;

use Illuminate\Support\Facades\Route;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Docs\Doctor\DocsLinkTargetsAudit;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * `docs.link-targets` (docs-walkthrough DOCS-02, rule DOC-6, the stored half): every same-origin URL in a stored entry
 * body resolves to an entry, a route, or a public file. It reads the STORED body (no re-seed, no render), so it runs on
 * every host as-is.
 */
class DocsLinkTargetsAuditTest extends TestCase
{
    private MemoryDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        $this->driver = new MemoryDriver;
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $this->driver));
        config(['app.url' => 'https://docs.example.test']);

        Route::get('/pricing', fn () => 'ok');
    }

    public function test_a_dead_stored_link_fails_naming_the_entry_and_the_path(): void
    {
        $this->entry('guide', "See [pricing](/pricing), [the missing page](/docs/gone) and <a href=\"https://docs.example.test/also-gone\">this</a>.\n"
            .'An external [site](https://elsewhere.test/x) and an [anchor](#top) are not judged.');

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('guide → /docs/gone', $finding->detail);
        $this->assertStringContainsString('guide → /also-gone', $finding->detail);
        $this->assertStringNotContainsString('/pricing', $finding->detail);
        $this->assertStringNotContainsString('elsewhere', $finding->detail);
        $this->assertStringContainsString('3 checked · 2 dead', $finding->detail);
    }

    public function test_links_that_resolve_to_a_route_an_entry_or_a_public_file_pass(): void
    {
        $this->entry('guide', 'Go to [pricing](/pricing) or [the favicon](/favicon.ico).');
        @mkdir(public_path(), 0777, true);
        touch(public_path('favicon.ico'));

        try {
            $finding = $this->finding();
        } finally {
            @unlink(public_path('favicon.ico'));
        }

        $this->assertSame(DoctorStatus::Pass, $finding->status, $finding->detail);
        $this->assertStringContainsString('2 checked · 0 dead', $finding->detail);
    }

    public function test_an_href_to_a_bare_hash_is_dead(): void
    {
        // DOC-6: no `href="#"`. A placeholder link goes nowhere; an `#anchor` does go somewhere and is not judged.
        $this->entry('landing', '<a href="#">Docs</a> <a href="#">Brand kit</a> <a href="#install">Install</a>');

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('docs.landing → href="#" ×2', $finding->detail);
    }

    public function test_no_stored_body_is_inconclusive(): void
    {
        $finding = $this->finding();

        $this->assertFalse($finding->conclusive);
    }

    private function entry(string $slug, string $mdx): void
    {
        $entry = BeamUxEntry::create(['slug' => $slug, 'namespace' => 'docs', 'type' => 'page', 'format' => 'mdx']);
        $item = $this->driver->write('', $entry->codec()->encode($mdx), 'docs');
        $entry->forceFill(['particle_id' => $item->key])->save();
    }

    private function finding(): Finding
    {
        $findings = array_values(array_filter(
            $this->app->make(DocsLinkTargetsAudit::class)->run(),
            fn (Finding $f) => $f->check === 'docs.link-targets',
        ));
        $this->assertCount(1, $findings);

        return $findings[0];
    }
}

/** An in-memory particle store. */
class MemoryDriver implements StorageDriver
{
    /** @var array<string, StorageItem> */
    private array $items = [];

    public function read(string $key): ?StorageItem
    {
        return $this->items[$key] ?? null;
    }

    public function write(string $key, array $body, ?string $namespace = null): StorageItem
    {
        $key = $key !== '' ? $key : 'p'.(count($this->items) + 1);

        return $this->items[$key] = new StorageItem($key, $body, $namespace, time());
    }

    public function list(?string $namespace = null): array
    {
        return array_values($this->items);
    }

    public function staleness(string $key, int $candidateModifiedAt): int
    {
        return 0;
    }
}
