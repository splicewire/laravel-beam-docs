<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use Illuminate\Support\Facades\File;
use Splicewire\Beam\Docs\BeamDocsServiceProvider;
use Splicewire\Beam\Docs\PhpApi\PhpApiArtifact;
use Splicewire\Beam\Docs\Tests\TestCase;

/**
 * A host's `config/beam/docs.php` meets the package defaults through Laravel's mergeConfigFrom, which is SHALLOW: a host
 * that sets `php_api` at all replaces the package's whole `php_api` block (php-api-reference slice 5, found adopting the
 * flagship). A host that lists only `php_api.packages`, the documented way to switch the reference on, therefore lost
 * `references`, and a PUBLIC artifact kept every ticket, ADR and tracker citation the policy exists to strip.
 *
 * Each test reproduces the host path: the host's `beam.docs` is in config BEFORE the provider registers (as a host's
 * config/ file is loaded before providers), then the provider's own register() runs mergeConfigFrom and packageRegistered.
 * Assertions name literal citations from fixture pkg-a (`launch ticket 23`, `APP-09b`, `ADR-0011`), never the configured
 * patterns: a config-derived check passes vacuously when the patterns are empty, which is the defect itself.
 */
class PhpApiHostConfigDefaultsTest extends TestCase
{
    private string $out;

    private const PKG_A = 'fixture/php-api-a';

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().'/beam-docs-php-api-host-config-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $hostDocs  The host's whole config/beam/docs.php */
    private function bootHostConfig(array $hostDocs): void
    {
        config(['beam.docs' => $hostDocs]);
        (new BeamDocsServiceProvider($this->app))->register();
    }

    /** @return array<int, string> */
    private function packageDefaultReferences(): array
    {
        return (require dirname(__DIR__, 2).'/config/beam/docs.php')['php_api']['references'];
    }

    private function packages(): array
    {
        return [self::PKG_A => dirname(__DIR__).'/Fixtures/PhpApi/pkg-a'];
    }

    private function generatePublic(): string
    {
        config(['beam.docs.php_api.artifact' => $this->out, 'beam.docs.visibility' => 'public']);
        $this->artisan('splicewire:beam:docs:php-api:generate')->assertExitCode(0);

        return collect(File::allFiles($this->out))->map(fn ($f) => $f->getContents())->implode("\n");
    }

    public function test_a_packages_only_host_keeps_the_shipped_reference_defaults(): void
    {
        $this->bootHostConfig(['php_api' => ['packages' => $this->packages()]]);

        $this->assertSame($this->packages(), config('beam.docs.php_api.packages'));
        $this->assertSame($this->packageDefaultReferences(), config('beam.docs.php_api.references'));
        $this->assertNotEmpty(config('beam.docs.php_api.references'));
        $this->assertArrayHasKey('artifact', config('beam.docs.php_api'));
        $this->assertArrayHasKey('audience', config('beam.docs.php_api'));
    }

    public function test_a_packages_only_host_strips_citations_from_public_output_and_its_reader_accepts_it(): void
    {
        $this->bootHostConfig(['php_api' => ['packages' => $this->packages()]]);

        $public = $this->generatePublic();

        $this->assertStringContainsString('A box that holds one value', $public);
        foreach (['launch ticket 23', 'APP-09b', 'ADR-0011'] as $citation) {
            $this->assertStringNotContainsString($citation, $public, "public output leaked {$citation}");
        }
        // The reader recomputes the input digest from the same effective patterns: it must accept what was generated.
        $this->assertNotNull(app(PhpApiArtifact::class)->manifest());
    }

    public function test_an_explicit_custom_reference_list_is_used_as_given_not_merged_with_the_defaults(): void
    {
        $custom = ['/\bADR[- ]?\d+\b/i'];
        $this->bootHostConfig(['php_api' => ['packages' => $this->packages(), 'references' => $custom]]);

        $this->assertSame($custom, config('beam.docs.php_api.references'));

        $public = $this->generatePublic();
        $this->assertStringNotContainsString('ADR-0011', $public);
        $this->assertStringContainsString('APP-09b', $public, 'a custom list replaces the defaults; it is not merged into them');
    }

    public function test_an_explicit_empty_reference_list_stays_empty(): void
    {
        $this->bootHostConfig(['php_api' => ['packages' => $this->packages(), 'references' => []]]);

        $this->assertSame([], config('beam.docs.php_api.references'));
    }

    public function test_a_host_without_php_api_keeps_the_package_block_and_the_reference_off(): void
    {
        $this->bootHostConfig(['sources' => []]);

        $this->assertSame([], config('beam.docs.php_api.packages'));
        $this->assertSame($this->packageDefaultReferences(), config('beam.docs.php_api.references'));
    }

    public function test_host_artifact_and_audience_settings_are_kept(): void
    {
        $this->bootHostConfig(['php_api' => ['packages' => $this->packages(), 'artifact' => '/host/artifact', 'audience' => 'internal']]);

        $this->assertSame('/host/artifact', config('beam.docs.php_api.artifact'));
        $this->assertSame('internal', config('beam.docs.php_api.audience'));
        $this->assertSame($this->packageDefaultReferences(), config('beam.docs.php_api.references'));
    }
}
