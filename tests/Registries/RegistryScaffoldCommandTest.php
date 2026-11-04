<?php

namespace Splicewire\Beam\Docs\Tests\Registries;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Rushing\Popcorn\Registries\Docs\RegistryDocsCheck;
use Splicewire\Beam\Docs\Registries\RegistryFactsBlock;
use Splicewire\Beam\Docs\Tests\TestCase;

/**
 * The scaffold command (registry-docs ticket 03; ticket 01 rulings 3, 6 and 9): one replaceable
 * `docs/registries/<root>.mdx` stub per undocumented declared root — frontmatter, a marker-delimited
 * generated-facts block (declaration facts only), an empty authored section. Regeneration rewrites
 * only the facts block; the baked description is a seed in an MDX comment, never rendered; a root
 * a manifest covers, or a page with no markers, is never written.
 *
 * The fixture package is materialized once per process in a temp dir — its `src/` holds the
 * declarations, so the census attributes them to it by path (ownership is by declaring file) —
 * and each test starts from an empty `docs/registries/`.
 */
class RegistryScaffoldCommandTest extends TestCase
{
    private const DESCRIPTION = 'Gamma baked prose for maintainers — see {@see GammaRegistry::register()}, and never close a comment */ early.';

    private static ?string $package = null;

    private string $docs;

    protected function setUp(): void
    {
        parent::setUp();

        $base = self::package();
        $this->docs = $base.'/docs/registries';
        (new Filesystem)->deleteDirectory($base.'/docs');
        mkdir($this->docs, 0700, true);

        config([
            'popcorn.bake.paths' => [$base.'/src'],
            'beam.docs.registries.packages' => ['splicewire/fixture-gamma' => $base],
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$package !== null) {
            (new Filesystem)->deleteDirectory(self::$package);
        }

        parent::tearDownAfterClass();
    }

    private function scaffold(): void
    {
        $this->artisan('splicewire:beam:docs:registries:scaffold', ['package' => 'splicewire/fixture-gamma'])->assertSuccessful();
    }

    public function test_a_stub_is_created_for_an_undocumented_root(): void
    {
        $this->scaffold();

        $stub = (string) file_get_contents($this->docs.'/fixture.gamma.mdx');

        $this->assertStringStartsWith("---\ntitle: fixture.gamma\nsegment: fixture.gamma\nnav_order: 7\n---\n", $stub, 'frontmatter the docs pipeline reads; the dotted root verbatim');

        $block = RegistryFactsBlock::extract($stub);
        $this->assertNotNull($block, 'the facts block is marker-delimited');
        $this->assertStringContainsString('- **Root:** `fixture.gamma`', $block);
        $this->assertStringContainsString('- **Entries:** `stdClass`', $block);
        $this->assertStringContainsString('- **Duplicate keys:** Reject', $block);
        $this->assertStringContainsString('- **Population:** Required', $block);
        $this->assertStringContainsString('- **Order:** `7` (display order, ascending)', $block);
        $this->assertStringContainsString('`Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Gamma\GammaRegistry`', $block);

        $this->assertStringContainsString('{/* registry-prose', $stub, 'the empty authored section follows the facts');
        $this->assertStringContainsString("\n".RegistryFactsBlock::END."\n\n<RegistryRegistrants root=\"fixture.gamma\" />\n", $stub, 'the live registrant panel follows the facts, outside the markers');
        $this->assertStringNotContainsString('RegistryRegistrants', (string) $block, 'a refresh never touches the panel tag');
        $this->assertFileExists($this->docs.'/fixture.gamma.second.mdx', 'one stub per undocumented declared root');
    }

    public function test_regeneration_rewrites_only_the_facts_block_and_preserves_prose(): void
    {
        $this->scaffold();
        $file = $this->docs.'/fixture.gamma.mdx';
        $fresh = (string) file_get_contents($file);
        $block = (string) RegistryFactsBlock::extract($fresh);

        // An author writes prose on both sides of the block, retitles the page, and someone
        // hand-edits a fact (drift).
        $authored = str_replace(
            ["title: fixture.gamma\n", $block],
            ["title: The gamma registry\nexcerpt: Hand-written.\n", "Lead paragraph above the facts.\n\n".str_replace('Duplicate keys:** Reject', 'Duplicate keys:** Supersede', $block)],
            $fresh,
        )."\n## Registering\n\nAuthored body, with a `{/* registry-facts:start */}` lookalike quoted in code.\n";
        file_put_contents($file, $authored);

        $this->scaffold();
        $regenerated = (string) file_get_contents($file);

        $this->assertSame($block, RegistryFactsBlock::extract($regenerated), 'the block is re-rendered from the declaration');
        $this->assertSame(str_replace('Duplicate keys:** Supersede', 'Duplicate keys:** Reject', $authored), $regenerated, 'every byte outside the markers is preserved');

        $this->artisan('splicewire:beam:docs:registries:scaffold', ['package' => 'splicewire/fixture-gamma'])
            ->expectsOutputToContain('0 created, 0 refreshed, 2 unchanged')
            ->assertSuccessful();
        $this->assertSame($regenerated, file_get_contents($file), 'an unchanged page is not rewritten');
    }

    public function test_the_baked_description_is_never_rendered(): void
    {
        $this->scaffold();
        $stub = (string) file_get_contents($this->docs.'/fixture.gamma.mdx');

        // Offered to the author as a seed, inside an MDX comment, made comment-safe.
        $this->assertStringContainsString('Gamma baked prose for maintainers', $stub);
        $this->assertStringNotContainsString('comment */ early', $stub, 'a `*/` in the description cannot close the comment');

        // What MDX renders is the source minus its comment expressions — the description is not in it.
        $rendered = (string) preg_replace('#\{/\*.*?\*/\}#s', '', $stub);
        $this->assertStringNotContainsString('Gamma baked prose', $rendered);
        $this->assertStringNotContainsString('maintainers', $rendered);

        // Nor in frontmatter: `description`/`excerpt` become the page's SEO description.
        $frontmatter = explode('---', $stub)[1];
        $this->assertStringNotContainsString('description:', $frontmatter);
        $this->assertStringNotContainsString('excerpt:', $frontmatter);
    }

    public function test_a_documented_root_is_left_alone(): void
    {
        file_put_contents($this->docs.'/manifest.json', json_encode([
            'external' => ['fixture.gamma.second' => 'https://example.com/gamma-second'],
        ]));
        $authored = "---\ntitle: Gamma\n---\n\n# Gamma\n\nA wholly hand-written page, no markers.\n";
        file_put_contents($this->docs.'/fixture.gamma.mdx', $authored);

        $this->artisan('splicewire:beam:docs:registries:scaffold', ['package' => 'splicewire/fixture-gamma'])
            ->expectsOutputToContain('0 created, 0 refreshed, 0 unchanged; left alone: 1 manifest-covered, 1 hand-authored.')
            ->assertSuccessful();

        $this->assertSame($authored, file_get_contents($this->docs.'/fixture.gamma.mdx'), 'a page without markers is never written');
        $this->assertFileDoesNotExist($this->docs.'/fixture.gamma.second.mdx', 'a manifest-covered root gets no stub');
    }

    public function test_the_root_filter_scaffolds_one_cluster_only(): void
    {
        $this->artisan('splicewire:beam:docs:registries:scaffold', ['package' => 'splicewire/fixture-gamma', '--root' => ['fixture.gamma.second']])
            ->assertSuccessful();

        $this->assertFileExists($this->docs.'/fixture.gamma.second.mdx');
        $this->assertFileDoesNotExist($this->docs.'/fixture.gamma.mdx');

        $this->artisan('splicewire:beam:docs:registries:scaffold', ['package' => 'splicewire/fixture-gamma', '--root' => ['fixture.gamma.*']])
            ->expectsOutputToContain('1 created, 0 refreshed, 1 unchanged')
            ->assertSuccessful();
    }

    /** A page as written before the stub carried the panel: the stub with its panel line taken out. */
    private function prePanelPage(string $root): string
    {
        $this->scaffold();
        $file = $this->docs.'/'.$root.'.mdx';
        $old = str_replace("\n\n".RegistryFactsBlock::panel($root), '', (string) file_get_contents($file))
            ."\n## Registering\n\nAuthored body.\n";
        file_put_contents($file, $old);
        $this->assertStringNotContainsString('RegistryRegistrants', $old);

        return $old;
    }

    public function test_panel_places_the_tag_on_the_line_after_the_facts_block(): void
    {
        $old = $this->prePanelPage('fixture.gamma');
        $file = $this->docs.'/fixture.gamma.mdx';

        $this->artisan('splicewire:beam:docs:registries:scaffold', ['package' => 'splicewire/fixture-gamma', '--panel' => true])
            ->expectsOutputToContain('Panel: 1 placed, 1 already present; 0 skipped (no facts block).')
            ->assertSuccessful();

        $placed = (string) file_get_contents($file);
        $this->assertSame(
            str_replace(RegistryFactsBlock::END."\n", RegistryFactsBlock::END."\n\n<RegistryRegistrants root=\"fixture.gamma\" />\n", $old),
            $placed,
            'the tag is the only new line, right after the end marker, a blank line on each side',
        );
        $this->assertSame(1, substr_count($placed, '<RegistryRegistrants'));
        $this->assertSame(RegistryFactsBlock::extract($old), RegistryFactsBlock::extract($placed), 'the facts block is untouched');
        $this->assertStringNotContainsString('RegistryRegistrants', (string) RegistryFactsBlock::extract($placed), 'never inside the markers');
    }

    public function test_panel_is_idempotent(): void
    {
        $this->prePanelPage('fixture.gamma');
        $args = ['package' => 'splicewire/fixture-gamma', '--panel' => true];

        $this->artisan('splicewire:beam:docs:registries:scaffold', $args)->assertSuccessful();
        $once = array_map('file_get_contents', glob($this->docs.'/*.mdx'));

        $this->artisan('splicewire:beam:docs:registries:scaffold', $args)
            ->expectsOutputToContain('Panel: 0 placed, 2 already present; 0 skipped (no facts block).')
            ->assertSuccessful();

        $this->assertSame($once, array_map('file_get_contents', glob($this->docs.'/*.mdx')), 'a second run writes nothing');

        // An author who moved the tag keeps their placement.
        $file = $this->docs.'/fixture.gamma.mdx';
        $moved = str_replace("\n\n<RegistryRegistrants root=\"fixture.gamma\" />", '', (string) file_get_contents($file))."\n<RegistryRegistrants root=\"fixture.gamma\" />\n";
        file_put_contents($file, $moved);
        $this->artisan('splicewire:beam:docs:registries:scaffold', $args)->assertSuccessful();
        $this->assertSame($moved, file_get_contents($file));
    }

    public function test_panel_leaves_the_facts_block_passing_the_adoption_check(): void
    {
        $this->prePanelPage('fixture.gamma');
        $base = dirname($this->docs, 2);

        $before = RegistryDocsCheck::forPackage($base)->run();
        $this->assertSame([], $before->failures(), $before->summary());

        $this->artisan('splicewire:beam:docs:registries:scaffold', ['package' => 'splicewire/fixture-gamma', '--panel' => true])->assertSuccessful();

        $after = RegistryDocsCheck::forPackage($base)->run();
        $this->assertSame([], $after->failures(), $after->summary());
        $this->assertCount(2, $after->coverage, 'the check is active and covers both fixture roots');
    }

    public function test_panel_skips_and_lists_a_page_with_no_facts_block(): void
    {
        $authored = "---\ntitle: Gamma\n---\n\n# Gamma\n\nA wholly hand-written page, no markers.\n";
        file_put_contents($this->docs.'/fixture.gamma.mdx', $authored);

        $this->artisan('splicewire:beam:docs:registries:scaffold', ['package' => 'splicewire/fixture-gamma', '--panel' => true])
            ->expectsOutputToContain('Panel: 0 placed, 1 already present; 1 skipped (no facts block).')
            ->expectsOutputToContain('no facts block, panel not placed: splicewire/fixture-gamma  docs/registries/fixture.gamma.mdx')
            ->assertSuccessful();

        $this->assertSame($authored, file_get_contents($this->docs.'/fixture.gamma.mdx'), 'a page without markers is never written');
    }

    public function test_without_panel_an_existing_page_gains_no_tag(): void
    {
        $old = $this->prePanelPage('fixture.gamma');

        $this->scaffold();

        $this->assertSame($old, file_get_contents($this->docs.'/fixture.gamma.mdx'), 'a refresh never adds the panel');
    }

    /** @return iterable<string, array{string, string}> */
    public static function panelShapes(): iterable
    {
        $end = RegistryFactsBlock::END;
        $tag = '<RegistryRegistrants root="r" />';

        yield 'blank line follows' => ["{$end}\n\n## Next\n", "{$end}\n\n{$tag}\n\n## Next\n"];
        yield 'text directly below' => ["{$end}\n## Next\n", "{$end}\n\n{$tag}\n\n## Next\n"];
        yield 'end of file, newline' => ["{$end}\n", "{$end}\n\n{$tag}\n"];
        yield 'end of file, none' => [$end, "{$end}\n\n{$tag}\n"];
    }

    #[DataProvider('panelShapes')]
    public function test_with_panel_keeps_one_blank_line_on_each_side(string $after, string $expected): void
    {
        $head = RegistryFactsBlock::START."\nfacts\n";

        $this->assertSame($head.$expected, RegistryFactsBlock::withPanel($head.$after, 'r'));
    }

    public function test_an_unknown_package_fails_without_writing(): void
    {
        $this->artisan('splicewire:beam:docs:registries:scaffold', ['package' => 'splicewire/nowhere'])
            ->expectsOutputToContain('is not a family package')
            ->assertFailed();

        $this->assertSame([], glob($this->docs.'/*.mdx'));
    }

    public function test_a_malformed_manifest_writes_nothing_for_its_package(): void
    {
        file_put_contents($this->docs.'/manifest.json', json_encode(['exempt' => ['fixture.gamma' => ['pointer' => 'x.md']]]));

        $this->artisan('splicewire:beam:docs:registries:scaffold', ['package' => 'splicewire/fixture-gamma'])
            ->expectsOutputToContain('non-empty `reason`')
            ->assertFailed();

        $this->assertSame([], glob($this->docs.'/*.mdx'));
    }

    /** The fixture package: declarations under `src/`, loaded once per process. */
    private static function package(): string
    {
        if (self::$package !== null) {
            return self::$package;
        }

        $base = sys_get_temp_dir().'/registry-scaffold-'.getmypid().'-'.bin2hex(random_bytes(4));
        mkdir($base.'/src', 0700, true);

        $description = var_export(self::DESCRIPTION, true);
        file_put_contents($base.'/src/GammaRegistry.php', <<<PHP
            <?php

            namespace Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Gamma;

            use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
            use Rushing\Popcorn\Registries\IsRegistry;
            use Rushing\Popcorn\Registries\OnKeyDuplicate;
            use Rushing\Popcorn\Registries\PopulationRequirement;
            use Rushing\Popcorn\Registries\RegistryKey;

            #[IsRegistry(root: 'fixture.gamma', entryType: \stdClass::class, onKeyDuplicate: OnKeyDuplicate::Reject, populationRequirement: PopulationRequirement::Required, description: {$description}, order: 7)]
            class GammaRegistry extends ConfigRegistry
            {
                protected function configKey(): string { return 'fixture.gamma'; }

                protected function keyFor(int|string \$index, mixed \$entry): RegistryKey|string { return (string) \$index; }
            }
            PHP);
        file_put_contents($base.'/src/GammaSecondRegistry.php', <<<'PHP'
            <?php

            namespace Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Gamma;

            use Rushing\Popcorn\Laravel\Registries\ConfigRegistry;
            use Rushing\Popcorn\Registries\IsRegistry;
            use Rushing\Popcorn\Registries\RegistryKey;

            #[IsRegistry(root: 'fixture.gamma.second')]
            class GammaSecondRegistry extends ConfigRegistry
            {
                protected function configKey(): string { return 'fixture.gamma.second'; }

                protected function keyFor(int|string $index, mixed $entry): RegistryKey|string { return (string) $index; }
            }
            PHP);

        require_once $base.'/src/GammaRegistry.php';
        require_once $base.'/src/GammaSecondRegistry.php';

        return self::$package = (string) realpath($base);
    }
}
