<?php

namespace Splicewire\Beam\Docs\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Splicewire\Beam\Docs\BeamDocsServiceProvider;
use Splicewire\Beam\Docs\Doctor\BeamDocsRetiredMigrationAudit;
use Splicewire\Beam\Docs\Tests\TestCase;
use Spatie\LaravelPackageTools\Package;

/** migration-flatten 07: the retired docs-root data stem is reported where a host still holds its published copy. */
class BeamDocsRetiredMigrationAuditTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/beam-docs-retired-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/shared', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root.'/{,shared/}*.php', GLOB_BRACE) as $file) {
            is_string($file) && @unlink($file);
        }
        @rmdir($this->root.'/shared');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_it_declares_the_retired_docs_root_stem_and_what_replaces_it(): void
    {
        $this->assertSame(['shared/adopt_docs_entry_requirement' => 'DocsSeeder::adopt() on seed (no migration)'], BeamDocsRetiredMigrationAudit::DOCS_RETIRED);
    }

    public function test_the_package_no_longer_publishes_the_retired_stem(): void
    {
        $package = new Package;
        $package->setBasePath(dirname(__DIR__, 2).'/src');
        (new BeamDocsServiceProvider($this->app))->configurePackage($package);

        $this->assertSame(['create_beam_docs_publications_table'], $package->migrationFileNames);
    }

    public function test_it_warns_about_a_published_copy_and_names_the_seeder(): void
    {
        file_put_contents($this->root.'/shared/2026_09_01_000000_adopt_docs_entry_requirement.php', '<?php // fixture');
        $warns = array_values(array_filter((new BeamDocsRetiredMigrationAudit($this->root))->run(), fn ($f) => $f->status === DoctorStatus::Warn));

        $this->assertCount(1, $warns);
        $this->assertStringContainsString('adopt_docs_entry_requirement', $warns[0]->detail);
        $this->assertStringContainsString('DocsSeeder::adopt()', $warns[0]->detail);
    }

    public function test_a_host_without_the_retired_copy_passes(): void
    {
        file_put_contents($this->root.'/2026_09_01_000000_create_beam_docs_publications_table.php', '<?php // fixture');
        $findings = (new BeamDocsRetiredMigrationAudit($this->root))->run();

        $this->assertSame([], array_values(array_filter($findings, fn ($f) => $f->status !== DoctorStatus::Pass)));
    }
}
