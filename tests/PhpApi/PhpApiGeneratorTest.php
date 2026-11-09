<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use Illuminate\Support\Facades\File;
use Rushing\Popcorn\Registries\RelativeUriKey;
use Splicewire\Beam\Docs\Console\GeneratePhpApiCommand;
use Splicewire\Beam\Docs\PhpApi\ArtifactWriter;
use Splicewire\Beam\Docs\PhpApi\PhpApiGenerator;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Install\BeamInstallManifest;

/**
 * php-api-reference slice 1: the static extractor and its artifact (owner PRD 2026-11-06, activation 2026-11-09).
 * Fixtures under tests/Fixtures/PhpApi: pkg-a (traits, enums, generics, promoted and readonly properties, attributes,
 * private and @internal members, an unmarked class, an @api+@internal class, an out-of-set parent, an uninstalled
 * referenced class, citation prose, a classmap legacy/ file, a syntax error, and top-level code that must never run),
 * pkg-b (cross-package inheritance) and pkg-c (no @api symbol at all).
 */
class PhpApiGeneratorTest extends TestCase
{
    private string $out;

    private string $sentinel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->out = sys_get_temp_dir().'/beam-docs-php-api-'.bin2hex(random_bytes(6));
        $this->sentinel = sys_get_temp_dir().'/beam-docs-php-api-executed-'.bin2hex(random_bytes(6));
        putenv('PHP_API_SENTINEL='.$this->sentinel);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        File::deleteDirectory($this->out.'-again');
        @unlink($this->sentinel);
        putenv('PHP_API_SENTINEL');
        parent::tearDown();
    }

    private function fixtures(): array
    {
        $base = dirname(__DIR__).'/Fixtures/PhpApi';

        return ['fixture/php-api-a' => $base.'/pkg-a', 'fixture/php-api-b' => $base.'/pkg-b', 'fixture/php-api-c' => $base.'/pkg-c'];
    }

    private function build(string $audience, ?array $packages = null): array
    {
        return (new PhpApiGenerator)->build($packages ?? $this->fixtures(), $audience, config('beam.docs.php_api.references'));
    }

    private function symbol(array $artifact, string $fqn): ?array
    {
        return $artifact['symbols'][ArtifactWriter::symbolPath($fqn)] ?? null;
    }

    public function test_internal_audience_shows_every_non_private_non_internal_symbol_with_its_whole_comment(): void
    {
        $a = $this->build('internal');

        $this->assertNotNull($this->symbol($a, 'Fixture\ApiA\Hidden'), 'unmarked symbols appear under internal');
        $this->assertNull($this->symbol($a, 'Fixture\ApiA\Secret'), '@internal is excluded under internal too');
        $box = $this->symbol($a, 'Fixture\ApiA\Box');
        $this->assertSame(['A box that holds one value (launch ticket 23).', 'APP-09b: boxes are immutable once made.', 'Read {@see Kind} for the kinds; ruling T23b3 explains why the side is fixed.', 'A plain paragraph that cites nothing stays whole.'], $box['doc']);
        $methods = array_column($box['methods'], 'name');
        $this->assertNotContains('secret', $methods, 'private methods are never collected');
        $this->assertNotContains('rawSide', $methods, '@internal members are excluded');
        $this->assertNotContains('SECRET', array_column($box['constants'], 'name'));
        $this->assertNotContains('hidden', array_column($box['properties'], 'name'), 'a private promoted property is not collected');
    }

    public function test_public_audience_publishes_only_api_symbols_strips_citations_and_withholds_the_rest_by_position(): void
    {
        $a = $this->build('public');

        $this->assertNull($this->symbol($a, 'Fixture\ApiA\Hidden'), 'an unmarked symbol is hidden on a public site');
        $this->assertNull($this->symbol($a, 'Fixture\ApiB\Plain'));
        $this->assertNull($this->symbol($a, 'Fixture\ApiA\Secret'));
        $box = $this->symbol($a, 'Fixture\ApiA\Box');
        $this->assertSame([
            'A box that holds one value.',
            'boxes are immutable once made.',
            'A plain paragraph that cites nothing stays whole.',
        ], $box['doc'], 'parenthetical and leading-tag citations stripped, sentences kept; the paragraph still citing a ruling withheld');
        $this->assertSame(['The default side.'], collect($box['constants'])->firstWhere('name', 'DEFAULT_SIDE')['doc'], 'member prose gets the same policy');

        $report = collect($a['report']['packages'])->firstWhere('name', 'fixture/php-api-a');
        // Withheld by POSITION (the symbol's own declared line, its attribute included), never by text.
        $this->assertContains(['symbol' => 'Fixture\ApiA\Box', 'member' => '', 'paragraph' => 2, 'file' => 'src/Box.php', 'line' => $box['line']], $report['withheld']);
        $this->assertSame(22, $box['line'], 'the #[AllowDynamicProperties] line, where the declaration starts');
        $this->assertGreaterThanOrEqual(3, $report['citationsStripped']);
        $this->assertSame(1, $report['hidden']['unmarked']);
        $this->assertSame(1, $report['hidden']['internal']);
    }

    public function test_no_reference_pattern_appears_anywhere_in_public_output_report_included(): void
    {
        config(['beam.docs.php_api.packages' => $this->fixtures(), 'beam.docs.php_api.audience' => 'public']);
        $this->artisan('splicewire:beam:docs:php-api:generate', ['--write-to' => $this->out])->assertExitCode(0);

        $patterns = config('beam.docs.php_api.references');
        $files = File::allFiles($this->out);
        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            foreach ($patterns as $pattern) {
                $this->assertSame(0, preg_match($pattern, $file->getContents()), "{$file->getRelativePathname()} matches {$pattern}");
            }
        }
    }

    public function test_signatures_capture_generics_traits_enums_promotion_readonly_attributes_and_defaults(): void
    {
        $a = $this->build('public');
        $box = $this->symbol($a, 'Fixture\ApiA\Box');

        $this->assertSame([['name' => 'T', 'bound' => 'object', 'default' => null, 'variance' => 'invariant']], $box['templates']);
        $this->assertSame([['fqn' => 'Fixture\ApiA\Contracts\Shape', 'published' => true]], $box['implements']);
        $this->assertSame([['fqn' => 'Fixture\ApiA\Concerns\HasName', 'published' => true]], $box['traits']);
        $this->assertSame([['name' => 'AllowDynamicProperties', 'arguments' => []]], $box['attributes']);
        $map = collect($box['methods'])->firstWhere('name', 'map');
        $this->assertSame('public function map(callable $fn, int &...$extra): static|Illuminate\Support\Collection|null', $map['signature']);
        $this->assertSame([['name' => 'U', 'bound' => null, 'default' => null, 'variance' => 'invariant']], $map['templates']);
        $make = collect($box['methods'])->firstWhere('name', 'make');
        $this->assertSame("public static function make(?object \$value = null, array \$options = ['a' => 1]): self", $make['signature']);
        $value = collect($box['properties'])->firstWhere('name', 'value');
        $this->assertTrue($value['promoted']);
        $this->assertTrue($value['readonly']);
        $this->assertSame('protected', collect($box['properties'])->firstWhere('name', 'side')['visibility']);
        $this->assertSame('2.0', collect($box['constants'])->firstWhere('name', 'DEFAULT_SIDE')['value']);

        $kind = $this->symbol($a, 'Fixture\ApiA\Kind');
        $this->assertSame('enum', $kind['kind']);
        $this->assertSame('string', $kind['backingType']);
        $this->assertSame(
            [['name' => 'Circle', 'value' => "'circle'", 'line' => 13, 'doc' => ['A round one.']], ['name' => 'Square', 'value' => "'square'", 'line' => 14, 'doc' => []]],
            array_map(fn (array $c): array => array_intersect_key($c, array_flip(['name', 'value', 'doc', 'line'])), $kind['cases']),
        );

        $this->assertSame('trait', $this->symbol($a, 'Fixture\ApiA\Concerns\HasName')['kind']);
        $this->assertSame(['final', 'readonly'], $this->symbol($a, 'Fixture\ApiA\Point')['modifiers']);
        $this->assertSame('interface', $this->symbol($a, 'Fixture\ApiA\Contracts\Shape')['kind']);
        $this->assertSame([['target' => 'Fixture\ApiA\Kind', 'published' => true]], $box['see'], '{@see Kind} resolves in the file\'s namespace');
    }

    public function test_cross_links_inherit_across_packages_and_stop_at_a_parent_outside_the_set(): void
    {
        $a = $this->build('public');

        $circle = $this->symbol($a, 'Fixture\ApiB\Circle');
        $this->assertSame([['fqn' => 'Fixture\ApiA\Box', 'published' => true]], $circle['extends']);
        $inherited = collect($circle['inherited'])->map(fn (array $m) => "{$m['kind']} {$m['name']} from {$m['from']}")->all();
        $this->assertContains('method map from Fixture\ApiA\Box', $inherited);
        $this->assertContains('method name from Fixture\ApiA\Concerns\HasName', $inherited, 'members from a parent\'s trait');
        $this->assertContains('constant DEFAULT_SIDE from Fixture\ApiA\Box', $inherited);
        $this->assertNotContains('method area from Fixture\ApiA\Box', $inherited, 'an overridden method is the child\'s own');
        $this->assertNotContains('method rawSide from Fixture\ApiA\Box', $inherited, '@internal never leaks through inheritance');

        $shape = $this->symbol($a, 'Fixture\ApiA\Contracts\Shape');
        $this->assertSame(['Fixture\ApiA\Box', 'Fixture\ApiB\Circle'], $shape['implementers'], 'a subclass implements through its published parent');

        $child = $this->symbol($a, 'Fixture\ApiA\Child');
        $this->assertSame([['fqn' => 'Illuminate\Support\Collection', 'published' => false]], $child['extends'], 'an out-of-set parent is plain text');
        $this->assertSame([], $child['inherited'], '... with no inherited list');

        $missing = $this->symbol($a, 'Fixture\ApiA\UsesMissing');
        $this->assertSame([['fqn' => 'Vendor\Uninstalled\Base', 'published' => false]], $missing['extends']);
        $this->assertSame('public function run(Vendor\Uninstalled\Thing $thing): void', $missing['methods'][0]['signature'], 'an uninstalled referenced class is named, not loaded');
    }

    public function test_package_roots_cover_psr4_and_classmap_report_parse_errors_and_never_execute_package_code(): void
    {
        $a = $this->build('public');

        $this->assertNotNull($this->symbol($a, 'Fixture_OldHelper'), 'the classmap legacy/ root is covered');
        $this->assertNotNull($this->symbol($a, 'Fixture\ApiA\SideEffect'), 'a file with top-level code is parsed for its declarations');
        $this->assertFileDoesNotExist($this->sentinel, 'package code was never executed');
        $this->assertFalse(class_exists('Fixture\ApiA\Box', false), 'nor loaded');

        $report = collect($a['report']['packages'])->firstWhere('name', 'fixture/php-api-a');
        $this->assertSame('src/Broken.php', $report['parseErrors'][0]['file']);
        $this->assertSame(13, $report['files'], 'autoload roots only (12 under src/, 1 classmap legacy/): autoload-dev tests/ is not the package API');
        $this->assertNull($this->symbol($a, 'Fixture\ApiA\Tests\ExampleTest'), 'an @api class under autoload-dev is never read');
    }

    public function test_the_manifest_answers_missing_unresolved_and_no_published_symbol_without_a_directory_walk(): void
    {
        $packages = [...$this->fixtures(), 'fixture/php-api-gone' => '/nonexistent/fixture/path', 'not a/valid name' => '/tmp', 'fixture/not-installed'];
        $a = $this->build('public', $packages);
        $rows = collect($a['manifest']['packages'])->keyBy('name');

        $this->assertSame(['ok', 1], [$rows['fixture/php-api-b']['status'], $rows['fixture/php-api-b']['published']]);
        $this->assertSame(['ok', 0], [$rows['fixture/php-api-c']['status'], $rows['fixture/php-api-c']['published']], 'no published symbol: listed, resolved, nothing @api');
        $this->assertSame('packages/fixture/php-api-c.json', $rows['fixture/php-api-c']['index']);
        $this->assertSame(['unresolved', null], [$rows['fixture/php-api-gone']['status'], $rows['fixture/php-api-gone']['index']]);
        $this->assertSame('unresolved', $rows['not a/valid name']['status'], 'an invalid name never forms a path');
        $this->assertSame('unresolved', $rows['fixture/not-installed']['status']);
        $this->assertSame(collect($a['manifest']['packages'])->sum('published'), $a['manifest']['totals']['published']);
        $this->assertSame(ArtifactWriter::inputDigest($packages, 'public', config('beam.docs.php_api.references')), $a['manifest']['inputDigest']);
        $this->assertStringNotContainsString(dirname(__DIR__), json_encode($a), 'no absolute path is written');

        foreach ($a['indexes'] as $index) {
            foreach ($index['symbols'] as $row) {
                $this->assertArrayHasKey($row['path'], $a['symbols'], 'every index row names a written symbol file');
            }
        }
    }

    public function test_generating_twice_is_byte_identical(): void
    {
        config(['beam.docs.php_api.packages' => $this->fixtures(), 'beam.docs.php_api.audience' => 'public']);
        $this->artisan('splicewire:beam:docs:php-api:generate', ['--write-to' => $this->out])->assertExitCode(0);
        $this->artisan('splicewire:beam:docs:php-api:generate', ['--write-to' => $this->out.'-again'])->assertExitCode(0);

        $tree = fn (string $dir): array => collect(File::allFiles($dir, true))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => hash('sha256', $f->getContents())])->sortKeys()->all();
        $first = $tree($this->out);
        $this->assertSame($first, $tree($this->out.'-again'));
        $this->assertArrayHasKey('manifest.json', $first);
        $this->assertArrayHasKey('.beam-docs-php-api', $first);

        // Regenerating over an existing artifact replaces it in place, still identical.
        $this->artisan('splicewire:beam:docs:php-api:generate', ['--write-to' => $this->out])->assertExitCode(0);
        $this->assertSame($first, $tree($this->out));

        // Real source can carry raw non-UTF-8 bytes in a literal (found by the real-source smoke as a JsonException):
        // the write substitutes U+FFFD deterministically instead of failing.
        $bytes = json_decode(File::get($this->out.'/'.ArtifactWriter::symbolPath('Fixture\ApiA\Bytes')), true);
        $this->assertStringContainsString("\u{FFFD}", $bytes['constants'][0]['value']);
    }

    public function test_the_command_is_a_no_op_on_an_empty_list_fails_on_an_unresolved_package_and_refuses_a_foreign_directory(): void
    {
        config(['beam.docs.php_api.packages' => []]);
        $this->artisan('splicewire:beam:docs:php-api:generate', ['--write-to' => $this->out])->assertExitCode(0);
        $this->assertDirectoryDoesNotExist($this->out, 'an empty list writes nothing');

        config(['beam.docs.php_api.packages' => ['fixture/php-api-gone' => '/nonexistent/fixture/path']]);
        $this->artisan('splicewire:beam:docs:php-api:generate', ['--write-to' => $this->out])->assertExitCode(1);
        $this->assertSame('unresolved', json_decode(File::get($this->out.'/manifest.json'), true)['packages'][0]['status']);

        $foreign = $this->out.'-again';
        File::ensureDirectoryExists($foreign);
        File::put($foreign.'/keep.txt', 'not ours');
        config(['beam.docs.php_api.packages' => $this->fixtures()]);
        $this->expectExceptionMessage('is not a PHP API artifact');

        try {
            $this->artisan('splicewire:beam:docs:php-api:generate', ['--write-to' => $foreign]);
        } finally {
            $this->assertFileExists($foreign.'/keep.txt', 'a foreign directory is never touched');
        }
    }

    public function test_the_audience_defaults_from_docs_visibility_and_the_install_manifest_runs_the_command(): void
    {
        config(['beam.docs.php_api.audience' => null, 'beam.docs.visibility' => 'public']);
        $this->assertSame('public', GeneratePhpApiCommand::audience());
        config(['beam.docs.visibility' => 'private']);
        $this->assertSame('internal', GeneratePhpApiCommand::audience());
        config(['beam.docs.php_api.audience' => 'public']);
        $this->assertSame('public', GeneratePhpApiCommand::audience());

        $step = $this->app->make(BeamInstallManifest::class)->resolve(RelativeUriKey::of('splicewire/laravel-beam-docs'));
        $this->assertContains('splicewire:beam:docs:php-api:generate', $step->commands);
    }
}
