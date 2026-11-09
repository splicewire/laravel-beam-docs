<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use Illuminate\Support\Facades\File;
use RuntimeException;
use Splicewire\Beam\Docs\PhpApi\ArtifactWriter;
use Splicewire\Beam\Docs\PhpApi\PhpApiGenerator;
use Splicewire\Beam\Docs\Tests\TestCase;

/**
 * php-api-reference slice 1, the ONE author amend for review REVISE fe468ff28: red-first controls for the two
 * artifact-safety findings (S1 symlink target, S2 failed publication) and the seven spec gaps (trait adaptations and
 * nesting, an internal override shadowing its parent, promotion under a private constructor, PHPDoc types and tag
 * descriptions, classmap globs/extensions/exclusions, member attributes, member `@see` resolution). Fixture pkg-d.
 */
class PhpApiAmendTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/beam-docs-php-api-amend-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (File::glob($this->tmp.'/*') as $path) {
            if (is_link($path)) {
                unlink($path);
            }
        }

        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function build(string $audience = 'public'): array
    {
        $base = dirname(__DIR__).'/Fixtures/PhpApi';

        return (new PhpApiGenerator)->build(['fixture/php-api-d' => $base.'/pkg-d'], $audience, config('beam.docs.php_api.references'));
    }

    private function symbol(array $artifact, string $fqn): ?array
    {
        return $artifact['symbols'][ArtifactWriter::symbolPath($fqn)] ?? null;
    }

    private function names(array $rows): array
    {
        $names = array_column($rows, 'name');
        sort($names);

        return $names;
    }

    private function tree(string $dir): array
    {
        return collect(File::allFiles($dir, true))->mapWithKeys(fn ($f) => [$f->getRelativePathname() => hash('sha256', $f->getContents())])->sortKeys()->all();
    }

    // ---- S1: a symlink target is refused and its destination's bytes are preserved ----

    public function test_s1_a_symlinked_target_is_refused_and_the_destination_is_never_deleted(): void
    {
        $destination = $this->tmp.'/real-artifact';
        File::ensureDirectoryExists($destination);
        File::put($destination.'/'.ArtifactWriter::MARKER, "marked\n");
        File::put($destination.'/precious.txt', 'owner bytes');
        $link = $this->tmp.'/link';
        symlink($destination, $link);
        $before = $this->tree($destination);

        try {
            (new ArtifactWriter)->write($this->build(), $link);
            $this->fail('a symlinked target was accepted');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('symlink', $e->getMessage());
        }

        $this->assertTrue(is_link($link), 'the link itself is left as it was');
        $this->assertSame($before, $this->tree($destination), 'the destination is byte-identical');
        $this->assertSame([], File::glob($this->tmp.'/*writing*'), 'no staging left behind');
    }

    // ---- S2: both renames checked; a failed publish restores, and a failed restore keeps a reported preimage ----

    public function test_s2_a_failed_publication_restores_the_previous_artifact_byte_for_byte(): void
    {
        $target = $this->tmp.'/artifact';
        (new ArtifactWriter)->write($this->build(), $target);
        $before = $this->tree($target);

        $writer = new ArtifactWriter(rename: function (string $from, string $to): bool {
            return str_contains($from, '.writing-') ? false : rename($from, $to); // the publish rename fails
        });

        try {
            $writer->write($this->build('internal'), $target);
            $this->fail('a failed publication reported success');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('restored', $e->getMessage());
        }

        $this->assertSame($before, $this->tree($target), 'the previous artifact is back, byte for byte');
        $this->assertSame([$target], File::glob($this->tmp.'/*'), 'no staging or displaced copy left behind');
    }

    public function test_s2_a_failed_restore_keeps_and_reports_the_recoverable_preimage_and_never_deletes_it(): void
    {
        $target = $this->tmp.'/artifact';
        (new ArtifactWriter)->write($this->build(), $target);
        $before = $this->tree($target);

        $writer = new ArtifactWriter(rename: function (string $from, string $to): bool {
            if (str_contains($from, '.writing-') || str_contains($from, '.replaced-')) {
                return false; // the publish fails, then the restore fails
            }

            return rename($from, $to);
        });

        try {
            $writer->write($this->build('internal'), $target);
            $this->fail('a failed publication and restore reported success');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/preserved at (\S+\.replaced-\w+)/', $e->getMessage());
            preg_match('/preserved at (\S+\.replaced-\w+)/', $e->getMessage(), $m);
            $this->assertSame($before, $this->tree($m[1]), 'the preimage is kept, byte for byte, where the error says');
        }

        $this->assertDirectoryDoesNotExist($target, 'nothing pretends to be the published artifact');
    }

    public function test_s2_a_failed_displacement_changes_nothing(): void
    {
        $target = $this->tmp.'/artifact';
        (new ArtifactWriter)->write($this->build(), $target);
        $before = $this->tree($target);
        $writer = new ArtifactWriter(rename: fn (string $from, string $to): bool => false);

        try {
            $writer->write($this->build('internal'), $target);
            $this->fail('a failed displacement reported success');
        } catch (RuntimeException) {
        }

        $this->assertSame($before, $this->tree($target));
        $this->assertSame([$target], File::glob($this->tmp.'/*'));
    }

    // ---- Spec 1: trait adaptations, precedence and nested composition ----

    public function test_spec1_trait_adaptations_precedence_and_nesting_give_the_effective_api(): void
    {
        $person = $this->symbol($this->build(), 'Fixture\ApiD\Person');
        $inherited = collect($person['inherited'])->mapWithKeys(fn (array $m) => ["{$m['kind']} {$m['name']}" => $m])->all();

        $this->assertSame('Fixture\ApiD\Traits\Greets', $inherited['method hello']['from'], 'Greets::hello insteadof Waves');
        $this->assertSame('Fixture\ApiD\Traits\Waves', $inherited['method waveHello']['from'], 'Waves::hello as waveHello');
        $this->assertSame('hello', $inherited['method waveHello']['original']);
        $this->assertArrayNotHasKey('method bye', $inherited, 'bye as private is not public API');
        $this->assertArrayHasKey('method revealed', $inherited, 'a private trait method aliased as public is');
        $this->assertArrayNotHasKey('method secretly', $inherited, 'the private original stays private');
        $this->assertSame('Fixture\ApiD\Traits\Nested', $inherited['method nestedMethod']['from'], 'a trait used by a used trait');
    }

    // ---- Spec 2: a hidden declaration shadows what it overrides ----

    public function test_spec2_an_internal_override_does_not_bring_the_parents_member_back(): void
    {
        $derived = $this->symbol($this->build(), 'Fixture\ApiD\Derived');

        $this->assertNotContains('run', array_column($derived['methods'], 'name'), 'the internal override is not published');
        $inherited = collect($derived['inherited'])->map(fn (array $m) => "{$m['kind']} {$m['name']}")->all();
        $this->assertNotContains('method run', $inherited, 'nor does Base::run reappear through it');
        $this->assertContains('method keep', $inherited);
    }

    // ---- Spec 3: promotion does not depend on the constructor's visibility ----

    public function test_spec3_public_promoted_properties_survive_a_private_constructor(): void
    {
        $made = $this->symbol($this->build(), 'Fixture\ApiD\Made');

        $this->assertSame(['name'], $this->names($made['properties']), 'public promoted kept, private promoted excluded');
        $this->assertTrue($made['properties'][0]['promoted']);
        $this->assertNotContains('__construct', array_column($made['methods'], 'name'), 'the private constructor stays out');
    }

    // ---- Spec 4: PHPDoc types, generics and tag descriptions ----

    public function test_spec4_phpdoc_types_generics_variance_default_and_tag_descriptions_are_preserved_under_the_audience_policy(): void
    {
        $typed = $this->symbol($this->build(), 'Fixture\ApiD\Typed');

        $this->assertSame([['name' => 'T', 'bound' => 'object', 'default' => '\stdClass', 'variance' => 'covariant']], $typed['templates']);
        $this->assertContains(['tag' => '@implements', 'type' => '\IteratorAggregate<int, T>'], $typed['tags']);
        $first = collect($typed['methods'])->firstWhere('name', 'first');
        $this->assertContains(['tag' => '@param', 'type' => 'list<T>', 'name' => '$items', 'description' => 'The items to hold.'], $first['tags'], 'the citation is stripped from a tag description under public');
        $this->assertContains(['tag' => '@return', 'type' => 'T', 'description' => 'The first one.'], $first['tags']);
        $this->assertContains(['tag' => '@throws', 'type' => '\RuntimeException', 'description' => 'When there are none.'], $first['tags']);

        $internal = collect($this->symbol($this->build('internal'), 'Fixture\ApiD\Typed')['methods'])->firstWhere('name', 'first');
        $this->assertContains(['tag' => '@param', 'type' => 'list<T>', 'name' => '$items', 'description' => 'The items to hold (ticket 12).'], $internal['tags'], 'internal keeps the whole description');
        $this->assertStringContainsString('@throws \RuntimeException When there are none.', $internal['comment'], 'internal carries the whole comment');
        $this->assertArrayNotHasKey('comment', $first, 'public never carries the raw comment');
    }

    public function test_spec4_no_reference_pattern_reaches_any_public_file_now_that_tag_descriptions_are_carried(): void
    {
        $target = $this->tmp.'/public';
        (new ArtifactWriter)->write($this->build('public'), $target);

        foreach (File::allFiles($target) as $file) {
            foreach (config('beam.docs.php_api.references') as $pattern) {
                $this->assertSame(0, preg_match($pattern, $file->getContents()), "{$file->getRelativePathname()} matches {$pattern}");
            }
        }
    }

    // ---- Spec 5: Composer classmap globs, extensions and exclusions ----

    public function test_spec5_classmap_globs_inc_hh_and_exclude_from_classmap_follow_composer(): void
    {
        $a = $this->build();

        $this->assertNotNull($this->symbol($a, 'Fixture_D_One'), 'a classmap glob root (lib/*/) is enumerated');
        $this->assertNull($this->symbol($a, 'Fixture_D_Skip'), 'exclude-from-classmap is honoured');
        $this->assertNotNull($this->symbol($a, 'Fixture_D_IncHelper'), '.inc in a classmap directory');
        $this->assertNotNull($this->symbol($a, 'Fixture_D_Hh'), '.hh in a classmap directory');
        $this->assertNull($this->symbol($a, 'Fixture_D_Txt'), 'other extensions are not classmap source');
        $report = collect($a['report']['packages'])->firstWhere('name', 'fixture/php-api-d');
        $this->assertSame([], $report['problems'], 'the glob root is not reported missing');
    }

    // ---- Spec 6: attributes on properties, constants, enum cases and promoted properties ----

    public function test_spec6_member_attributes_are_captured(): void
    {
        $a = $this->build();
        $marker = fn (string $where) => [['name' => 'Fixture\ApiD\Marker', 'arguments' => ["'{$where}'"]]];

        $attributed = $this->symbol($a, 'Fixture\ApiD\Attributed');
        $this->assertSame($marker('constant'), $attributed['constants'][0]['attributes']);
        $this->assertSame($marker('property'), $attributed['properties'][0]['attributes']);
        $this->assertSame($marker('case'), $this->symbol($a, 'Fixture\ApiD\AttributedCase')['cases'][0]['attributes']);
        $this->assertSame($marker('promoted'), $this->symbol($a, 'Fixture\ApiD\Made')['properties'][0]['attributes']);
    }

    // ---- Spec 7: member @see targets against the effective published members ----

    public function test_spec7_member_see_links_resolve_only_to_published_effective_members(): void
    {
        $links = collect($this->symbol($this->build(), 'Fixture\ApiD\Links')['see'])->pluck('published', 'target')->all();

        $this->assertTrue($links['Fixture\ApiD\Base::keep()'], 'a published member');
        $this->assertFalse($links['Fixture\ApiD\Base::missing()'], 'a member that does not exist');
        $this->assertFalse($links['Fixture\ApiD\Derived::run()'], 'an @internal member (not inherited through it either)');
        $this->assertTrue($links['Fixture\ApiD\Base::run()']);
        $this->assertTrue($links['Fixture\ApiD\Made::$name'], 'a published promoted property');
        $this->assertArrayNotHasKey('Fixture\ApiD\Hidden', $links, 'an @internal method contributes no @see');
    }
}
