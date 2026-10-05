<?php

namespace Splicewire\Beam\Docs\Tests\Doctor;

use Illuminate\Filesystem\Filesystem;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Docs\Doctor\DocsUnregisteredAudit;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `docs.unregistered` (docs-walkthrough DOCS-01, DOC-3): a file under a declared docs source with no row is a FAIL
 * naming the path, because the host declared the root and a missing row contradicts its own config. The audit iterates
 * FILES, through the importer's own plan, and reports `matched · unregistered · residual`.
 */
class DocsUnregisteredAuditTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();

        $this->dir = sys_get_temp_dir().'/docs-unregistered-'.bin2hex(random_bytes(4));
        foreach (['docs/page/intro.mdx', 'docs/build/page/deploy.mdx', 'docs/build/page/scale.mdx'] as $file) {
            @mkdir(dirname("{$this->dir}/{$file}"), 0777, true);
            file_put_contents("{$this->dir}/{$file}", "# {$file}\n");
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_three_files_and_two_rows_fail_naming_the_one_without_a_row(): void
    {
        config(['beam.docs.sources' => [['path' => $this->dir, 'type' => 'page', 'product' => 'beam']]]);
        $this->row('intro', 'docs');
        $this->row('deploy', 'docs.build');

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('docs/build/page/scale.mdx', $finding->detail);
        $this->assertStringNotContainsString('deploy.mdx', $finding->detail);
        $this->assertStringContainsString('2 matched · 1 unregistered · residual 0', $finding->detail);
    }

    public function test_every_file_with_a_row_passes_and_reports_residual_rows_without_a_file(): void
    {
        config(['beam.docs.sources' => [['path' => $this->dir, 'type' => 'page']]]);
        $this->row('intro', 'docs');
        $this->row('deploy', 'docs.build');
        $this->row('scale', 'docs.build');
        $this->row('retired', 'docs.build');

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('3 matched · 0 unregistered · residual 1', $finding->detail);
    }

    public function test_a_declared_root_that_does_not_exist_fails(): void
    {
        config(['beam.docs.sources' => [['path' => $this->dir.'/missing']]]);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('missing', $finding->detail);
    }

    public function test_no_declared_source_is_inconclusive(): void
    {
        config(['beam.docs.sources' => []]);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertFalse($finding->conclusive);
    }

    private function row(string $slug, string $namespace): void
    {
        BeamUxEntry::create(['slug' => $slug, 'namespace' => $namespace, 'type' => 'page']);
    }

    private function finding(): Finding
    {
        $findings = array_values(array_filter(
            $this->app->make(DocsUnregisteredAudit::class)->run(),
            fn (Finding $f) => $f->check === 'docs.unregistered',
        ));
        $this->assertCount(1, $findings);

        return $findings[0];
    }
}
