<?php

namespace Splicewire\Beam\Docs\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Docs\Doctor\DocsUnregisteredAudit;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\Docs\Seed\DocsSourcesSeeder;
use Splicewire\Beam\Seed\BeamSeedManifest;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * Sources in the seed chain (docs-walkthrough DOCS-05, rule DOC-3): the seed materializes every declared source
 * (`beam.docs.sources`), honouring its `ignore` globs, so no operator command is required and the `docs.unregistered`
 * audit passes on a fresh seed. A step that cannot account for its sources throws, which `splicewire:beam:seed` turns
 * into a non-zero exit (beam-docs-satellite 46).
 */
class DocsSourcesSeedTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        Storage::fake('docs-artifacts');
        config(['beam.ux.compile.disk' => 'docs-artifacts']);

        $driver = Mockery::mock(StorageDriver::class);
        $driver->shouldReceive('write')->andReturnUsing(fn ($key, $body, $namespace) => new StorageItem((string) Str::uuid(), $body, $namespace, time()));
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $driver));
        $compiler = Mockery::mock(EntryBodyCompiler::class);
        $compiler->shouldReceive('handles')->andReturnTrue();
        $compiler->shouldReceive('compile')->andReturn('export default () => null;');
        $this->app->instance(EntryBodyCompiler::class, $compiler);

        $this->dir = sys_get_temp_dir().'/docs-sources-'.bin2hex(random_bytes(4));
        foreach (['docs/page/using.mdx', 'docs/build/page/deploy.mdx', 'docs/build/page/scale.mdx', 'fragments/auth-note.mdx'] as $file) {
            @mkdir(dirname("{$this->dir}/{$file}"), 0777, true);
            file_put_contents("{$this->dir}/{$file}", "# {$file}\n");
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_the_seed_registers_every_declared_file_except_the_ignored_and_the_audit_then_passes(): void
    {
        config(['beam.docs.sources' => [['path' => $this->dir, 'type' => 'page', 'ignore' => ['fragments/**']]]]);
        $this->assertSame(DoctorStatus::Fail, $this->finding()->status, 'Before the seed the declared files have no rows.');

        (new DocsSourcesSeeder)->run();

        $this->assertEqualsCanonicalizing(['using', 'deploy', 'scale'], BeamUxEntry::query()->pluck('slug')->all());
        $this->assertNull(BeamUxEntry::query()->where('slug', 'auth-note')->first(), 'An ignored file never becomes a row.');
        $finding = $this->finding();
        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('3 matched · 0 unregistered · residual 0', $finding->detail);

        // Idempotent: a re-seed creates nothing (create-once, ADR-0209 §11).
        (new DocsSourcesSeeder)->run();
        $this->assertSame(3, BeamUxEntry::query()->count());
    }

    public function test_the_audit_does_not_count_an_ignored_file_as_unregistered(): void
    {
        config(['beam.docs.sources' => [['path' => $this->dir, 'type' => 'page', 'ignore' => ['fragments/**', 'docs/build/**']]]]);
        BeamUxEntry::create(['slug' => 'using', 'namespace' => 'docs', 'type' => 'page']);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('1 matched · 0 unregistered', $finding->detail);
    }

    public function test_a_declared_source_missing_on_disk_fails_the_step(): void
    {
        config(['beam.docs.sources' => [['path' => $this->dir.'/missing', 'type' => 'page']]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing');

        (new DocsSourcesSeeder)->run();
    }

    public function test_files_with_no_inferrable_type_fail_the_step_and_write_nothing(): void
    {
        config(['beam.docs.sources' => [['path' => $this->dir]]]);

        try {
            (new DocsSourcesSeeder)->run();
            $this->fail('A source whose files have no type must fail the seed step.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fragments/auth-note.mdx', $e->getMessage());
        }
        $this->assertSame(0, BeamUxEntry::query()->count());
    }

    public function test_no_declared_source_is_a_no_op(): void
    {
        config(['beam.docs.sources' => []]);

        (new DocsSourcesSeeder)->run();

        $this->assertSame(0, BeamUxEntry::query()->count());
    }

    public function test_the_step_is_in_the_seed_chain_after_the_docs_root(): void
    {
        $seeders = array_map(fn ($step) => $step->seeder, $this->app->make(BeamSeedManifest::class)->steps());

        $this->assertContains(DocsSourcesSeeder::class, $seeders);
        $this->assertGreaterThan(array_search(DocsSeeder::class, $seeders, true), array_search(DocsSourcesSeeder::class, $seeders, true));
    }

    private function finding(): Finding
    {
        return collect($this->app->make(DocsUnregisteredAudit::class)->run())->firstWhere('check', 'docs.unregistered');
    }
}
