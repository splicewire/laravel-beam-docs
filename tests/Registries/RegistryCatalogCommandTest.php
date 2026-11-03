<?php

namespace Splicewire\Beam\Docs\Tests\Registries;

use Illuminate\Filesystem\Filesystem;
use Splicewire\Beam\Docs\Registries\RegistryDocsCatalog;
use Splicewire\Beam\Docs\Tests\TestCase;

/**
 * The catalog regeneration command (registry-docs ticket 01, amended rulings 2–5): built from the
 * `#[IsRegistry]` declaration census plus every known family package's `docs/registries/`
 * (conventional pages and `manifest.json`), total over the census, validated by EXACT ROOT
 * MEMBERSHIP — dead roots and wrong-owner pointers fail the run with nothing written. The command
 * is the only writer; reading the catalog never creates it.
 */
class RegistryCatalogCommandTest extends TestCase
{
    private string $fixtures;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtures = dirname(__DIR__).'/Fixtures/RegistryDocs/packages';

        foreach (['alpha/src/AlphaRegistry.php', 'alpha/src/AlphaExternalRegistry.php', 'alpha/src/AlphaExemptRegistry.php', 'alpha/src/AlphaOverrideRegistry.php', 'beta/src/BetaRegistry.php'] as $file) {
            require_once $this->fixtures.'/'.$file;
        }

        config([
            // The census seam: the scan walks exactly these roots, so the census is the fixtures'
            // five declarations and nothing this install happens to carry.
            'popcorn.bake.paths' => [$this->fixtures.'/alpha/src', $this->fixtures.'/beta/src'],
            // The estate seam: packages the command reads manifests/pages from, merged over the
            // installed enumeration (which owns none of the fixture declaration files).
            'beam.docs.registries.packages' => [
                'splicewire/fixture-alpha' => $this->fixtures.'/alpha',
                'splicewire/fixture-beta' => $this->fixtures.'/beta',
            ],
        ]);

        $this->out = sys_get_temp_dir().'/registry-catalog-'.bin2hex(random_bytes(4));
        mkdir($this->out, 0700, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->out);

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function regenerate(string $file = 'catalog.json'): array
    {
        $path = $this->out.'/'.$file;

        $this->artisan('splicewire:beam:docs:registries:catalog', ['--write-to' => $path])->assertSuccessful();

        return json_decode((string) file_get_contents($path), true);
    }

    public function test_the_catalog_is_total_over_the_census_with_each_satisfaction_shape(): void
    {
        $catalog = $this->regenerate();

        $this->assertSame(['fixture.alpha', 'fixture.alpha.exempt', 'fixture.alpha.external', 'fixture.alpha.override', 'fixture.beta'], array_keys($catalog['roots']));

        $alpha = $catalog['roots']['fixture.alpha'];
        $this->assertSame('splicewire/fixture-alpha', $alpha['owner']);
        $this->assertSame(['kind' => 'page', 'path' => 'docs/registries/fixture.alpha.mdx', 'url' => '/docs/registries/fixture.alpha'], $alpha['doc']);

        $override = $catalog['roots']['fixture.alpha.override'];
        $this->assertSame('page', $override['doc']['kind']);
        $this->assertSame('docs/guides/override-page.mdx', $override['doc']['path']);
        $this->assertArrayNotHasKey('url', $override['doc'], 'an override carries no URL — the serving host\'s entries table resolves its placement');

        $this->assertSame(['kind' => 'external', 'url' => 'https://example.com/docs/registries#fixture-alpha-external'], $catalog['roots']['fixture.alpha.external']['doc']);

        $exempt = $catalog['roots']['fixture.alpha.exempt']['doc'];
        $this->assertSame('exempt', $exempt['kind']);
        $this->assertSame('rebind-only: hosts rebind this seam, nothing registers into it', $exempt['reason']);
        $this->assertSame('docs/guides/override-page.mdx', $exempt['pointer']);

        // An uncovered root is a VISIBLE row with a null pointer, never a missing row.
        $this->assertSame(['owner' => 'splicewire/fixture-beta', 'doc' => null], $catalog['roots']['fixture.beta']);

        // The empty root is structurally skipped: the census never emits it.
        $this->assertArrayNotHasKey('', $catalog['roots']);
    }

    public function test_regeneration_is_idempotent_and_the_reader_round_trips_what_the_command_wrote(): void
    {
        $first = $this->regenerate();
        $second = $this->regenerate();

        $this->assertSame($first, $second);

        config(['beam.docs.registries.catalog' => $this->out.'/catalog.json']);
        $rows = app(RegistryDocsCatalog::class)->fresh()->rows();

        $this->assertCount(5, $rows);
        $this->assertSame('external', $rows['fixture.alpha.external']->kind);
        $this->assertSame('/docs/registries/fixture.alpha', $rows['fixture.alpha']->url);
        $this->assertNull($rows['fixture.beta']->kind);
    }

    public function test_a_dead_root_in_a_manifest_fails_the_run_and_writes_nothing(): void
    {
        $this->temporaryPackage('splicewire/fixture-dead', ['manifest.json' => json_encode([
            'external' => ['fixture.nowhere' => 'https://example.com/nowhere'],
        ])]);

        $path = $this->out.'/dead.json';
        $this->artisan('splicewire:beam:docs:registries:catalog', ['--write-to' => $path])
            ->expectsOutputToContain('dead root')
            ->assertFailed();

        $this->assertFileDoesNotExist($path);
    }

    public function test_a_root_declared_by_another_package_fails_the_run(): void
    {
        $this->temporaryPackage('splicewire/fixture-thief', ['manifest.json' => json_encode([
            'external' => ['fixture.alpha' => 'https://example.com/not-yours'],
        ])]);

        $this->artisan('splicewire:beam:docs:registries:catalog', ['--write-to' => $this->out.'/thief.json'])
            ->expectsOutputToContain('declared by splicewire/fixture-alpha')
            ->assertFailed();
    }

    public function test_an_exemption_without_a_reason_fails_the_run(): void
    {
        $this->temporaryPackage('splicewire/fixture-beta-shadow', ['manifest.json' => json_encode([
            'exempt' => ['fixture.beta' => ['pointer' => 'docs/why.md']],
        ])], shadows: 'splicewire/fixture-beta');

        $this->artisan('splicewire:beam:docs:registries:catalog', ['--write-to' => $this->out.'/noreason.json'])
            ->expectsOutputToContain('non-empty `reason`')
            ->assertFailed();
    }

    public function test_an_empty_root_manifest_key_is_structurally_skipped_not_an_error(): void
    {
        $this->temporaryPackage('splicewire/fixture-beta-shadow', ['manifest.json' => json_encode([
            'external' => ['' => 'https://example.com/the-index-itself'],
        ])], shadows: 'splicewire/fixture-beta');

        $catalog = $this->regenerate('empty-key.json');

        $this->assertArrayNotHasKey('', $catalog['roots']);
    }

    public function test_reading_a_missing_catalog_never_writes_one(): void
    {
        config(['beam.docs.registries.catalog' => $this->out.'/never-written.json']);

        $this->assertSame([], app(RegistryDocsCatalog::class)->fresh()->rows());
        $this->assertFileDoesNotExist($this->out.'/never-written.json');
    }

    /**
     * Add a throwaway package (its own dir under the test temp root) to the catalog seam.
     *
     * @param  array<string, string>  $files  path under docs/registries => contents
     */
    private function temporaryPackage(string $name, array $files, ?string $shadows = null): void
    {
        $base = $this->out.'/'.str_replace('/', '-', $name);
        mkdir($base.'/docs/registries', 0700, true);

        foreach ($files as $file => $contents) {
            file_put_contents($base.'/docs/registries/'.$file, $contents);
        }

        $packages = (array) config('beam.docs.registries.packages');

        if ($shadows !== null) {
            // Re-home the shadowed package's name at the temp dir, so the temp manifest OWNS the
            // root it names (the declaring file is matched by path prefix, so we point the name
            // at a dir that cannot own it — the test wants the shape error, not the owner error).
            unset($packages[$shadows]);
        }

        $packages[$name] = $base;
        config(['beam.docs.registries.packages' => $packages]);
    }
}
