<?php

namespace Splicewire\Beam\Docs\Tests\Doctor;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Docs\Doctor\DocsPhpApiAudit;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Doctor\BeamDoctorManifest;

/**
 * php-api-reference slice 2: `docs.php-api` is where SOURCE staleness is judged (a GET never parses or walks source).
 * An empty list is the explicit off state and passes. FAIL: a listed package that does not resolve, an artifact that is
 * missing, unreadable, for another audience or list, or older than the source it was generated from. Advisory (WARN):
 * withheld paragraphs per package, and a listed package with no `@api` symbol under `public`.
 */
class DocsPhpApiAuditTest extends TestCase
{
    private string $out;

    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().'/beam-docs-php-api-audit-'.bin2hex(random_bytes(6));
        $this->src = sys_get_temp_dir().'/beam-docs-php-api-audit-src-'.bin2hex(random_bytes(6));
        File::copyDirectory(dirname(__DIR__).'/Fixtures/PhpApi/pkg-a', $this->src);
        config([
            'beam.docs.visibility' => 'public',
            'beam.docs.php_api.audience' => null,
            'beam.docs.php_api.artifact' => $this->out,
            'beam.docs.php_api.packages' => ['fixture/php-api-a' => $this->src],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        File::deleteDirectory($this->src);
        parent::tearDown();
    }

    private function generate(): void
    {
        Artisan::call('splicewire:beam:docs:php-api:generate');
    }

    /** @return list<Finding> */
    private function findings(): array
    {
        return app(DocsPhpApiAudit::class)->run();
    }

    private function only(): Finding
    {
        $findings = $this->findings();
        $this->assertCount(1, $findings);
        $this->assertSame(DocsPhpApiAudit::CHECK, $findings[0]->check);

        return $findings[0];
    }

    public function test_it_is_registered_with_the_doctor(): void
    {
        $this->assertContains(DocsPhpApiAudit::class, array_map(fn ($r) => $r->audit, app(BeamDoctorManifest::class)->registrations()));
    }

    public function test_an_empty_list_is_the_explicit_off_state_and_passes(): void
    {
        config(['beam.docs.php_api.packages' => []]);
        $finding = $this->only();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('off', $finding->detail);
    }

    public function test_a_fresh_artifact_reports_its_advisories_only(): void
    {
        $this->generate();
        $finding = $this->only();

        // pkg-a carries citation-only paragraphs that the public audience withholds: advisory, not failure.
        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('fixture/php-api-a', $finding->detail);
        $this->assertMatchesRegularExpression('/\d+ paragraph\(s\) withheld/', $finding->detail);
        $this->assertStringNotContainsString('APP-09b', $finding->detail, 'the advisory never echoes withheld text');
    }

    public function test_a_clean_fresh_artifact_passes(): void
    {
        config(['beam.docs.php_api.audience' => 'internal']);
        $this->generate();

        $this->assertSame(DoctorStatus::Pass, $this->only()->status, 'internal withholds nothing and pkg-a publishes');
    }

    public function test_a_missing_artifact_fails(): void
    {
        $finding = $this->only();

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('missing', $finding->detail);
        $this->assertStringContainsString('splicewire:beam:docs:php-api:generate', $finding->detail);
    }

    public function test_a_corrupt_or_foreign_artifact_fails(): void
    {
        $this->generate();
        file_put_contents($this->out.'/manifest.json', '{');
        $this->assertSame(DoctorStatus::Fail, $this->only()->status);

        $this->generate();
        config(['beam.docs.php_api.audience' => 'internal']);
        $finding = $this->only();
        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('audience', $finding->detail);
    }

    public function test_a_changed_package_list_fails_as_stale(): void
    {
        $this->generate();
        config(['beam.docs.php_api.packages' => ['fixture/php-api-a' => $this->src, 'fixture/php-api-c' => dirname(__DIR__).'/Fixtures/PhpApi/pkg-c']]);

        $finding = $this->only();
        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('stale', $finding->detail);
    }

    public function test_edited_source_fails_as_stale_naming_the_package(): void
    {
        $this->generate();
        file_put_contents($this->src.'/src/Point.php', "\n// edited after generation\n", FILE_APPEND);

        $finding = $this->only();
        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('stale', $finding->detail);
        $this->assertStringContainsString('fixture/php-api-a', $finding->detail);
    }

    public function test_an_added_source_file_fails_as_stale(): void
    {
        $this->generate();
        file_put_contents($this->src.'/src/Added.php', "<?php\n\nnamespace Fixture\\ApiA;\n\nclass Added {}\n");

        $this->assertSame(DoctorStatus::Fail, $this->only()->status);
    }

    public function test_an_unresolved_package_fails(): void
    {
        config(['beam.docs.php_api.packages' => ['fixture/php-api-a' => $this->src, 'fixture/gone' => $this->src.'-absent', 'vendor/not-installed-here']]);
        $this->generate();

        $finding = $this->only();
        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('fixture/gone', $finding->detail);
        $this->assertStringContainsString('vendor/not-installed-here', $finding->detail);
        $this->assertStringContainsString('unresolved', $finding->detail);
    }

    public function test_a_listed_package_with_no_api_symbols_under_public_is_advisory(): void
    {
        config(['beam.docs.php_api.packages' => ['fixture/php-api-a' => $this->src, 'fixture/php-api-c' => dirname(__DIR__).'/Fixtures/PhpApi/pkg-c']]);
        $this->generate();

        $finding = $this->only();
        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('fixture/php-api-c: no @api symbols', $finding->detail);
    }

    public function test_the_audit_never_loads_package_code(): void
    {
        $sentinel = sys_get_temp_dir().'/beam-docs-php-api-audit-executed-'.bin2hex(random_bytes(6));
        putenv('PHP_API_SENTINEL='.$sentinel);
        try {
            $this->generate();
            $this->findings();
            $this->assertFileDoesNotExist($sentinel);
        } finally {
            putenv('PHP_API_SENTINEL');
            @unlink($sentinel);
        }
    }
}
