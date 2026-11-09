<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Compile\EntryBodyCompiler;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * php-api-reference slice 2: the PHP API page is ALWAYS seeded, beside the OpenAPI reference, carrying the
 * `beam-docs-php-api` requirement. Whether it shows is the requirement's answer at request time, so turning the
 * package list on or off never needs a reseed; and the requirement is re-asserted on every seed, so a row that lost it
 * cannot serve the data routes without the gate.
 */
class PhpApiSeedTest extends TestCase
{
    /** @var array<string, string> slug => the body handed to the compiler */
    private array $compiled = [];

    protected function setUp(): void
    {
        parent::setUp();
        $beam = dirname((new \ReflectionClass(BeamParticle::class))->getFileName(), 3);
        (require $beam.'/database/migrations/shared/create_beam_versions_table.php.stub')->up();
        (require $beam.'/database/migrations/shared/create_beam_particles_table.php.stub')->up();
        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        Storage::fake('docs-artifacts');
        config(['beam.ux.compile.disk' => 'docs-artifacts', 'beam.docs.sources' => [], 'beam.docs.php_api.packages' => []]);
        $driver = Mockery::mock(StorageDriver::class);
        $driver->shouldReceive('write')->andReturnUsing(fn ($key, $body, $namespace) => new StorageItem((string) Str::uuid(), $body, $namespace, time()));
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $driver));
        $compiler = Mockery::mock(EntryBodyCompiler::class);
        $compiler->shouldReceive('handles')->andReturnTrue();
        $compiler->shouldReceive('compile')->andReturnUsing(function ($entry, $body) {
            $this->compiled[$entry->slug] = $body;

            return 'export default () => null;';
        });
        $this->app->instance(EntryBodyCompiler::class, $compiler);
    }

    public function test_the_page_is_seeded_after_the_api_reference_with_the_requirement_even_with_an_empty_list(): void
    {
        (new DocsSeeder)->run();

        $root = BeamUxEntry::where('slug', 'docs')->sole();
        $api = BeamUxEntry::where('slug', 'docs-api')->sole();
        $php = BeamUxEntry::where('slug', 'docs-php-api')->sole();
        $this->assertSame($root->getKey(), $php->parent_id);
        $this->assertSame('php', $php->segment);
        $this->assertSame('SpreadTemplate', $php->template);
        $this->assertSame('PHP API Reference', $php->title);
        $this->assertSame(['beam-docs-php-api'], $php->requirements);
        $this->assertGreaterThan($api->nav_order, $php->nav_order, 'beside, after, the OpenAPI reference');
        $this->assertStringContainsString('<PhpApiReference />', $this->compiled['docs-php-api']);
        $this->assertNull($php->access, 'published like the other packaged pages; the requirement decides visibility');
    }

    public function test_toggling_the_list_changes_nothing_seeded(): void
    {
        (new DocsSeeder)->run();
        $before = BeamUxEntry::where('slug', 'docs-php-api')->sole()->getAttributes();

        config(['beam.docs.php_api.packages' => ['fixture/php-api-a' => dirname(__DIR__).'/Fixtures/PhpApi/pkg-a']]);
        (new DocsSeeder)->run();

        $after = BeamUxEntry::where('slug', 'docs-php-api')->sole()->getAttributes();
        $this->assertSame(1, BeamUxEntry::where('slug', 'docs-php-api')->count());
        foreach (['id', 'segment', 'template', 'requirements', 'access', 'parent_id'] as $column) {
            $this->assertSame($before[$column], $after[$column], $column);
        }
    }

    public function test_a_reseed_re_asserts_the_requirement_and_keeps_site_edits(): void
    {
        (new DocsSeeder)->run();
        BeamUxEntry::where('slug', 'docs-php-api')->update(['requirements' => json_encode(['site-own']), 'title' => 'Our PHP API']);

        (new DocsSeeder)->run();

        $php = BeamUxEntry::where('slug', 'docs-php-api')->sole();
        $this->assertSame(['site-own', 'beam-docs-php-api'], $php->requirements);
        $this->assertSame('Our PHP API', $php->title, 'an edited row is the site\'s; only the gate is re-asserted');

        BeamUxEntry::where('slug', 'docs-php-api')->update(['requirements' => null]);
        (new DocsSeeder)->run();
        $this->assertSame(['beam-docs-php-api'], BeamUxEntry::where('slug', 'docs-php-api')->sole()->requirements);
    }

    public function test_the_stub_is_a_spread_surface_placing_the_component(): void
    {
        $stub = (string) file_get_contents(dirname(__DIR__, 2).'/stubs/docs/php-api.mdx');

        $this->assertMatchesRegularExpression('/^template: SpreadTemplate$/m', $stub);
        $this->assertMatchesRegularExpression('/^segment: php$/m', $stub);
        $this->assertStringContainsString('<PhpApiReference />', $stub);
    }
}
