<?php

namespace Splicewire\Beam\Docs\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Docs\Doctor\DocsReferenceSubjectAudit;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `docs.reference-subject` (docs-walkthrough DOC-12, DOCS-10): what an API reference documents is declared, a public spec
 * carries no `beam.ux.api_root` operation and no framework-default title, and a product root with no product artifact
 * publishes no reference surface. Measured before the fix: beam.test and splicewire.test both served "Laravel API" with
 * `/beam/ux/artifacts/{entry}/{version}` (and splicewire.test the CMS ops) in the spec, and splicewire.test/beam/docs/api
 * rendered the host's own routes as Beam's reference.
 */
class DocsReferenceSubjectAuditTest extends TestCase
{
    private string $artifact;

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        $this->artifact = sys_get_temp_dir().'/docs10-'.uniqid().'.yaml';
        config(['beam.ux.api_root' => 'beam/ux']);
    }

    protected function tearDown(): void
    {
        @unlink($this->artifact);
        parent::tearDown();
    }

    private function spec(string $title, array $paths): void
    {
        $yaml = "openapi: 3.0.3\ninfo:\n  title: '{$title}'\n  version: 1.0.0\npaths:\n";
        foreach ($paths as $path) {
            $yaml .= "  {$path}:\n    get:\n      summary: x\n";
        }
        file_put_contents($this->artifact, $yaml);
        config(['beam.docs.openapi.subject' => 'self', 'beam.core.openapi.artifact' => $this->artifact]);
    }

    /** @return array<string, Finding> by check */
    private function findings(): array
    {
        $out = [];
        foreach (app(DocsReferenceSubjectAudit::class)->run() as $finding) {
            $out[$finding->check] = $finding;
        }

        return $out;
    }

    public function test_a_public_spec_with_an_api_root_operation_fails_naming_it(): void
    {
        $this->spec('Beam API', ['/api/v1/things', '/beam/ux/artifacts/{entry}/{version}']);

        $finding = $this->findings()[DocsReferenceSubjectAudit::API_ROOT];

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('/beam/ux/artifacts/{entry}/{version}', $finding->detail);
        $this->assertStringNotContainsString('/api/v1/things', $finding->detail);
    }

    public function test_a_spec_without_api_root_operations_passes(): void
    {
        $this->spec('Beam API', ['/api/v1/things']);

        $this->assertSame(DoctorStatus::Pass, $this->findings()[DocsReferenceSubjectAudit::API_ROOT]->status);
    }

    public function test_the_framework_default_title_fails_and_a_brand_title_passes(): void
    {
        $this->spec('Laravel API', ['/api/v1/things']);
        $this->assertSame(DoctorStatus::Fail, $this->findings()[DocsReferenceSubjectAudit::TITLE]->status);

        $this->spec('Beam API', ['/api/v1/things']);
        $this->assertSame(DoctorStatus::Pass, $this->findings()[DocsReferenceSubjectAudit::TITLE]->status);
    }

    public function test_a_product_subject_with_no_artifact_fails_while_the_reference_row_is_published(): void
    {
        config(['beam.docs.openapi.subject' => 'product:splicewire/laravel-beam', 'beam.docs.openapi.artifact' => null, 'beam.core.openapi.artifact' => null]);
        BeamUxEntry::create(['slug' => 'docs-api', 'title' => 'API Reference', 'type' => 'page', 'realm' => 'site']);

        $finding = $this->findings()[DocsReferenceSubjectAudit::SURFACE];

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('product:splicewire/laravel-beam', $finding->detail);

        BeamUxEntry::query()->where('slug', 'docs-api')->update(['access' => '[]']);
        $this->assertSame(DoctorStatus::Pass, $this->findings()[DocsReferenceSubjectAudit::SURFACE]->status, 'unpublished by access, not deleted');
    }

    public function test_a_self_subject_publishes_its_own_reference(): void
    {
        $this->spec('Beam API', ['/api/v1/things']);
        BeamUxEntry::create(['slug' => 'docs-api', 'title' => 'API Reference', 'type' => 'page', 'realm' => 'site']);

        $this->assertSame(DoctorStatus::Pass, $this->findings()[DocsReferenceSubjectAudit::SURFACE]->status);
    }

    public function test_no_artifact_is_inconclusive_for_the_spec_checks(): void
    {
        config(['beam.docs.openapi.subject' => 'self', 'beam.core.openapi.artifact' => sys_get_temp_dir().'/docs10-missing.yaml']);

        $findings = $this->findings();

        $this->assertFalse($findings[DocsReferenceSubjectAudit::API_ROOT]->conclusive);
        $this->assertFalse($findings[DocsReferenceSubjectAudit::TITLE]->conclusive);
    }
}
