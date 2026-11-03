<?php

namespace Splicewire\Beam\Docs\Tests\Doctor;

use Illuminate\Filesystem\Filesystem;
use Rushing\Doctor\DoctorRegistration;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Rushing\Popcorn\Registries\RegistryIndex;
use Splicewire\Beam\Docs\Doctor\RegistryDocsCoverageAudit;
use Splicewire\Beam\Docs\Registries\FamilyPackages;
use Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Alpha\AlphaExemptRegistry;
use Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Alpha\AlphaExternalRegistry;
use Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Alpha\AlphaOverrideRegistry;
use Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Alpha\AlphaRegistry;
use Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Beta\BetaRegistry;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Doctor\BeamDoctorManifest;

/**
 * `docs.registries.coverage` (registry-docs ticket 04): the host tier of the two-tier registry docs
 * audit. Each test builds a fixture HOST, meaning a runtime index holding only the fixture registries
 * and an installed-package list holding only the fixture packages, so the population is exactly what
 * the test describes.
 *
 * The alpha fixture package covers its four roots in four ways (page, override, external, exempt).
 * Beta ships no docs at all.
 */
class RegistryDocsCoverageAuditTest extends TestCase
{
    private string $fixtures;

    private ?string $tmp = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = dirname(__DIR__).'/Fixtures/RegistryDocs/packages';

        foreach (['alpha/src/AlphaRegistry.php', 'alpha/src/AlphaExternalRegistry.php', 'alpha/src/AlphaExemptRegistry.php', 'alpha/src/AlphaOverrideRegistry.php', 'beta/src/BetaRegistry.php'] as $file) {
            require_once $this->fixtures.'/'.$file;
        }

        config(['beam.docs.registries.catalog' => sys_get_temp_dir().'/no-catalog-'.bin2hex(random_bytes(4)).'.json']);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            (new Filesystem)->deleteDirectory($this->tmp);
        }

        parent::tearDown();
    }

    /**
     * @param  list<class-string>  $registries
     * @param  array<string, string>  $packages
     */
    private function host(array $registries, array $packages): Finding
    {
        $index = new RegistryIndex;

        foreach ($registries as $class) {
            $index->describe(new $class($this->app['config']));
        }

        $this->app->instance(RegistryIndex::class, $index);
        $this->app->instance(FamilyPackages::class, new class($packages) extends FamilyPackages
        {
            /** @param  array<string, string>  $packages */
            public function __construct(private array $packages) {}

            public function installed(): array
            {
                return $this->packages;
            }
        });

        $findings = app(RegistryDocsCoverageAudit::class)->run();
        $this->assertCount(1, $findings);

        return $findings[0];
    }

    /** @return list<class-string> */
    private function alphaRegistries(): array
    {
        return [AlphaRegistry::class, AlphaOverrideRegistry::class, AlphaExternalRegistry::class, AlphaExemptRegistry::class];
    }

    public function test_it_is_registered_into_the_doctor_manifest_as_advisory(): void
    {
        $registration = collect(app(BeamDoctorManifest::class)->registrations())
            ->first(fn (DoctorRegistration $r): bool => $r->audit === RegistryDocsCoverageAudit::class);

        $this->assertNotNull($registration, 'beam-docs registers the host-tier audit into beam.doctor.audits');
        $this->assertSame('splicewire/laravel-beam-docs', $registration->package);
        $this->assertFalse($registration->gate, 'advisory first (ruling 5): a Fail never blocks the doctor exit code');
    }

    public function test_a_covered_host_passes_and_states_its_scope_as_this_hosts_population(): void
    {
        $finding = $this->host($this->alphaRegistries(), ['splicewire/fixture-alpha' => $this->fixtures.'/alpha']);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString("Scope: this host's runtime registry index, 4 root(s) (empty root excluded), a host population and not a fleet count", $finding->detail);
        $this->assertStringContainsString('4 covered (1 exempt · 1 external · 1 override · 1 page) · 0 uncovered.', $finding->detail);
    }

    public function test_an_uncovered_host_fails_naming_the_root_and_never_lists_the_empty_root(): void
    {
        $finding = $this->host([...$this->alphaRegistries(), BetaRegistry::class], [
            'splicewire/fixture-alpha' => $this->fixtures.'/alpha',
            'splicewire/fixture-beta' => $this->fixtures.'/beta',
        ]);

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('5 root(s) (empty root excluded)', $finding->detail, 'the index\'s own `\'\'` is in the index and never in the population');
        $this->assertStringContainsString('4 covered (1 exempt · 1 external · 1 override · 1 page) · 1 uncovered.', $finding->detail);
        $this->assertStringContainsString('Roots with no page, manifest pointer or exemption: fixture.beta.', $finding->detail);
    }

    public function test_a_committed_catalog_row_covers_a_root_this_host_installs_no_docs_for(): void
    {
        $this->tmp = sys_get_temp_dir().'/registry-coverage-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700, true);
        file_put_contents($this->tmp.'/catalog.json', json_encode(['version' => 1, 'roots' => [
            'fixture.beta' => ['owner' => 'splicewire/fixture-beta', 'doc' => ['kind' => 'external', 'url' => 'https://example.com/beta']],
        ]]));
        config(['beam.docs.registries.catalog' => $this->tmp.'/catalog.json']);

        $finding = $this->host([AlphaRegistry::class, BetaRegistry::class], ['splicewire/fixture-alpha' => $this->fixtures.'/alpha']);

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertStringContainsString('2 covered (1 catalog external · 1 page) · 0 uncovered.', $finding->detail);
    }

    public function test_an_exemption_missing_its_required_pointer_does_not_cover_and_the_problem_is_named(): void
    {
        $this->tmp = sys_get_temp_dir().'/registry-coverage-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/docs/registries', 0700, true);
        file_put_contents($this->tmp.'/docs/registries/manifest.json', json_encode([
            'exempt' => ['fixture.beta' => ['class' => 'infrastructure', 'reason' => 'kernel plumbing']],
        ]));

        $finding = $this->host([BetaRegistry::class], ['splicewire/fixture-beta' => $this->tmp]);

        $this->assertSame(DoctorStatus::Fail, $finding->status);
        $this->assertStringContainsString('fixture.beta', $finding->detail);
        $this->assertStringContainsString('is exempted as `infrastructure` and must carry a `pointer`', $finding->detail);
    }
}
