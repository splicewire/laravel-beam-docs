<?php

namespace Splicewire\Beam\Docs\Tests\Doctor;

use Illuminate\Filesystem\Filesystem;
use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Docs\Doctor\DocsLocalEnvAudit;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

class DocsLocalEnvAuditTest extends TestCase
{
    private LocalEnvMemoryDriver $driver;
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        $this->driver = new LocalEnvMemoryDriver;
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $this->driver));
        config(['beam.docs.sources' => []]);
        $this->dir = sys_get_temp_dir().'/docs-local-env-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_a_stored_token_fails_naming_the_entry_and_count_without_echoing_body(): void
    {
        $this->entry('XDEBUG_MODE=off php artisan test; XDEBUG_MODE=off secret-example');
        $finding = $this->app->make(DocsLocalEnvAudit::class)->run()[0];
        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('docs.guide → XDEBUG_MODE ×2', $finding->detail);
        $this->assertStringNotContainsString('secret-example', $finding->detail);
    }

    public function test_local_toolchain_spellings_warn(): void
    {
        $this->entry('herd php artisan test; herd composer install');
        $finding = $this->app->make(DocsLocalEnvAudit::class)->run()[0];
        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('herd php ×1', $finding->detail);
        $this->assertStringContainsString('herd composer ×1', $finding->detail);
    }

    public function test_clean_body_passes_with_the_explicit_starter_markdown_blind_spot(): void
    {
        $this->entry('composer setup; php artisan serve');
        $finding = $this->app->make(DocsLocalEnvAudit::class)->run()[0];
        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertTrue($finding->conclusive);
        $this->assertStringContainsString("cannot see the starters' repo markdown", $finding->detail);
    }

    public function test_no_stored_bodies_is_inconclusive(): void
    {
        $finding = $this->app->make(DocsLocalEnvAudit::class)->run()[0];
        $this->assertFalse($finding->conclusive);
    }

    public function test_disk_tokens_fail_before_import_and_ignored_files_are_excluded(): void
    {
        file_put_contents($this->dir.'/guide.mdx', '# XDEBUG_MODE=off');
        file_put_contents($this->dir.'/ignored.mdx', 'XDEBUG_MODE=off XDEBUG_MODE=off');
        config(['beam.docs.sources' => [['path' => $this->dir, 'ignore' => ['ignored.mdx']]]]);
        $finding = $this->app->make(DocsLocalEnvAudit::class)->run()[0];
        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('guide.mdx → XDEBUG_MODE ×1', $finding->detail);
        $this->assertStringNotContainsString('ignored.mdx →', $finding->detail);
        $this->assertSame(0, BeamUxEntry::count());
    }

    public function test_a_missing_declared_source_cannot_pass(): void
    {
        $this->entry('clean');
        config(['beam.docs.sources' => [['path' => $this->dir.'/missing']]]);
        $this->assertSame(DoctorStatus::Warn, $this->app->make(DocsLocalEnvAudit::class)->run()[0]->status);
    }

    private function entry(string $body): void
    {
        $entry = BeamUxEntry::create(['slug' => 'guide', 'namespace' => 'docs', 'type' => 'page', 'format' => 'mdx']);
        $item = $this->driver->write('', $entry->codec()->encode($body), 'docs');
        $entry->forceFill(['particle_id' => $item->key])->save();
    }
}

class LocalEnvMemoryDriver implements StorageDriver
{
    private array $items = [];
    public function read(string $key): ?StorageItem { return $this->items[$key] ?? null; }
    public function write(string $key, array $body, ?string $namespace = null): StorageItem
    {
        $key = $key ?: 'p'.(count($this->items) + 1);
        return $this->items[$key] = new StorageItem($key, $body, $namespace, time());
    }
    public function list(?string $namespace = null): array { return array_values($this->items); }
    public function staleness(string $key, int $candidateModifiedAt): int { return 0; }
}
