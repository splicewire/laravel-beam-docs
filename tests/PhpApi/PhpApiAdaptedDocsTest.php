<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use PHPUnit\Framework\Attributes\DataProvider;
use Splicewire\Beam\Docs\PhpApi\ArtifactWriter;
use Splicewire\Beam\Docs\PhpApi\PhpApiGenerator;
use Splicewire\Beam\Docs\Tests\TestCase;

/**
 * php-api-reference 01b, its ONE amend (review REVISE 181b26dc): an adapted trait declaration's documentation is
 * complete and correctly attributed.
 * (1) The resolved `@see` / `{@see}` targets of a VISIBLE adapted declaration reach the consumer's links (cross-file and
 *     cross-package), in both audiences; a private method that is never aliased, and an @internal one aliased public,
 *     contribute nothing.
 * (2) A paragraph still withheld in an adapted declaration is reported where it must be rewritten: the ORIGIN package's
 *     report, the origin file and line, with the consumer and alias identity alongside. The text stays withheld, and a
 *     private original's name appears nowhere. Fixtures pkg-e (Citing/Cites, cross-file) and pkg-f (Remote, used by
 *     pkg-e's Bridge, cross-package).
 */
class PhpApiAdaptedDocsTest extends TestCase
{
    private function build(string $audience): array
    {
        $base = dirname(__DIR__).'/Fixtures/PhpApi';

        return (new PhpApiGenerator)->build(['fixture/php-api-e' => $base.'/pkg-e', 'fixture/php-api-f' => $base.'/pkg-f'], $audience, config('beam.docs.php_api.references'));
    }

    private function see(array $artifact, string $fqn): array
    {
        return collect($artifact['symbols'][ArtifactWriter::symbolPath($fqn)]['see'])->pluck('published', 'target')->all();
    }

    private function report(array $artifact, string $package): array
    {
        return collect($artifact['report']['packages'])->firstWhere('name', $package);
    }

    public static function audiences(): array
    {
        return ['public' => ['public'], 'internal' => ['internal']];
    }

    #[DataProvider('audiences')]
    public function test_a_visible_adapted_declaration_carries_its_resolved_see_targets_cross_file(string $audience): void
    {
        $see = $this->see($this->build($audience), 'Fixture\ApiE\Cites');

        $this->assertTrue($see['Fixture\ApiE\Parent_'] ?? null, 'privateCite as public cited: its @see Parent_ is now published, resolved');
        $this->assertArrayNotHasKey('Fixture\ApiE\Needs', $see, 'a private method never aliased contributes nothing');
        $this->assertArrayNotHasKey('Fixture\ApiE\ChildOfHidden', $see, 'an @internal method aliased public contributes nothing');
    }

    #[DataProvider('audiences')]
    public function test_a_visible_adapted_declaration_carries_its_inline_see_cross_package(string $audience): void
    {
        $see = $this->see($this->build($audience), 'Fixture\ApiE\Bridge');

        $this->assertTrue($see['Fixture\ApiE\Needs'] ?? null, 'farAwayMethod as public near (pkg-f): its {@see Needs} is resolved and published');
    }

    public function test_a_withheld_adapted_paragraph_is_reported_at_its_origin_file_line_and_package(): void
    {
        $artifact = $this->build('public');
        $e = $this->report($artifact, 'fixture/php-api-e');
        $f = $this->report($artifact, 'fixture/php-api-f');

        $this->assertContains(['symbol' => 'Fixture\ApiE\Cites', 'member' => 'cited()', 'paragraph' => 1, 'file' => 'src/Citing.php', 'line' => 19, 'declaredIn' => 'Fixture\ApiE\Citing'], $e['withheld'], 'cross-file: the trait\'s file and line, not the consumer\'s file');
        $this->assertContains(['symbol' => 'Fixture\ApiE\Bridge', 'member' => 'near()', 'paragraph' => 1, 'file' => 'src/Remote.php', 'line' => 19, 'declaredIn' => 'Fixture\ApiF\Remote'], $f['withheld'], 'cross-package: reported in the ORIGIN package');
        $this->assertSame([], array_filter($e['withheld'], fn (array $r): bool => $r['symbol'] === 'Fixture\ApiE\Bridge'), 'not in the consumer package\'s report');
        $this->assertSame(count($f['withheld']), count(array_filter($f['withheld'], fn (array $r): bool => str_starts_with($r['file'], 'src/'))), 'every locator in a package report is a file of that package');
    }

    #[DataProvider('audiences')]
    public function test_private_original_names_and_withheld_text_reach_no_artifact_file(string $audience): void
    {
        $artifact = $this->build($audience);
        $text = json_encode([$artifact['symbols'], $artifact['indexes'], $artifact['manifest'], $artifact['report']]);

        $this->assertStringNotContainsString('privateCite', $text);
        $this->assertStringNotContainsString('farAwayMethod', $text);
        $this->assertStringNotContainsString('neverAliased', $text);

        if ($audience === 'public') {
            $this->assertStringNotContainsString('T23 explains', $text, 'withheld text stays withheld');
            $this->assertStringNotContainsString('keeps this note', $text);
        }
    }

    public function test_the_internal_audience_withholds_nothing_for_these_declarations(): void
    {
        $artifact = $this->build('internal');

        $this->assertSame([], $this->report($artifact, 'fixture/php-api-e')['withheld']);
        $this->assertSame([], $this->report($artifact, 'fixture/php-api-f')['withheld']);
    }
}
