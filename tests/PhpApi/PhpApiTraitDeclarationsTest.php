<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use PHPUnit\Framework\Attributes\DataProvider;
use Splicewire\Beam\Docs\PhpApi\ArtifactWriter;
use Splicewire\Beam\Docs\PhpApi\PhpApiGenerator;
use Splicewire\Beam\Docs\Tests\TestCase;

/**
 * php-api-reference 01b-effective-trait-declarations (owner/root disposition 2026-11-09 03:07Z; final 01 review
 * 2dbcc0df): (1) an adapted trait method (an alias, or a visibility change) carries its full EFFECTIVE declaration
 * and visibility, while a private original's name and content and any @internal member stay withheld; (2) an abstract
 * trait requirement stays a requirement and resolves to the actual concrete implementation by PHP precedence (a
 * parent's, here), with the trait both published and unpublished, in both audiences. Fixture pkg-e.
 */
class PhpApiTraitDeclarationsTest extends TestCase
{
    private function build(string $audience): array
    {
        return (new PhpApiGenerator)->build(['fixture/php-api-e' => dirname(__DIR__).'/Fixtures/PhpApi/pkg-e'], $audience, config('beam.docs.php_api.references'));
    }

    private function symbol(array $artifact, string $fqn): ?array
    {
        return $artifact['symbols'][ArtifactWriter::symbolPath($fqn)] ?? null;
    }

    private function inherited(array $symbol): array
    {
        return collect($symbol['inherited'])->keyBy(fn (array $m) => "{$m['kind']} {$m['name']}")->all();
    }

    public static function audiences(): array
    {
        return ['public' => ['public'], 'internal' => ['internal']];
    }

    #[DataProvider('audiences')]
    public function test_a_public_alias_of_a_private_method_carries_its_full_effective_declaration(string $audience): void
    {
        $kit = $this->inherited($this->symbol($this->build($audience), 'Fixture\ApiE\Kit'));
        $revealed = $kit['method revealed'];

        $this->assertSame('Fixture\ApiE\Tools', $revealed['from']);
        $this->assertSame('public', $revealed['visibility'], 'the adapted visibility, not the private original\'s');
        $this->assertArrayNotHasKey('original', $revealed, 'a private original\'s name is withheld');
        $declaration = $revealed['declaration'];
        $this->assertSame('revealed', $declaration['name']);
        $this->assertSame('public', $declaration['visibility']);
        $this->assertSame('public function revealed(int $times = 2, ?string $label = null): void', $declaration['signature']);
        $this->assertSame(['times', 'label'], array_column($declaration['params'], 'name'));
        $this->assertSame(['2', 'null'], array_column($declaration['params'], 'default'));
        $this->assertSame('void', $declaration['returnType']);
        $this->assertSame([['name' => 'Fixture\ApiE\Marker', 'arguments' => ["'quietly'"]]], $declaration['attributes']);
        $this->assertSame($audience === 'public' ? ['Does it quietly.'] : ['Does it quietly (ticket 7).'], $declaration['doc'], 'audience-filtered docs');
        $this->assertSame([['tag' => '@param', 'type' => 'int', 'name' => '$times', 'description' => 'How often.']], $declaration['tags']);
        $this->assertSame($audience === 'internal', array_key_exists('comment', $declaration));
    }

    #[DataProvider('audiences')]
    public function test_visibility_adaptations_carry_their_declaration_and_unadapted_members_do_not(string $audience): void
    {
        $kit = $this->inherited($this->symbol($this->build($audience), 'Fixture\ApiE\Kit'));

        $this->assertSame('protected', $kit['method visible']['visibility'], 'visible as protected');
        $this->assertSame('protected function visible(string $value): string', $kit['method visible']['declaration']['signature']);
        $this->assertSame('protected', $kit['method loud']['visibility'], 'shout as protected loud');
        $this->assertSame('shout', $kit['method loud']['original'], 'a public original keeps its name');
        $this->assertSame('protected function loud(): void', $kit['method loud']['declaration']['signature']);
        $this->assertSame('public', $kit['method shout']['visibility']);
        $this->assertArrayNotHasKey('declaration', $kit['method shout'], 'an unadapted member links to its declaring symbol');
        $this->assertArrayNotHasKey('method quiet', $kit, 'an @internal method aliased public stays withheld');
        $this->assertArrayNotHasKey('method hush', $kit);
        $this->assertArrayNotHasKey('method secretly', $kit);
    }

    #[DataProvider('audiences')]
    public function test_no_private_original_or_internal_member_reaches_any_artifact_file(string $audience): void
    {
        $artifact = $this->build($audience);
        $text = json_encode([$artifact['symbols'], $artifact['indexes'], $artifact['manifest']]);

        $this->assertStringNotContainsString('secretly', $text, 'the private original\'s name');
        $this->assertStringNotContainsString('hush', $text, 'the @internal original');
        $this->assertStringNotContainsString('"quiet"', $text, 'its alias');
    }

    #[DataProvider('audiences')]
    public function test_an_abstract_requirement_from_a_published_trait_resolves_to_the_concrete_parent_implementation(string $audience): void
    {
        $artifact = $this->build($audience);
        $child = $this->inherited($this->symbol($artifact, 'Fixture\ApiE\ChildOfPublished'));

        $this->assertSame('Fixture\ApiE\Parent_', $child['method foo']['from'], 'the parent\'s concrete foo satisfies the trait\'s requirement');
        $this->assertFalse($child['method foo']['abstract']);
        $this->assertSame('Fixture\ApiE\RequiresPublished', $child['method bar']['from'], 'a concrete trait method still overrides the parent\'s');
        $this->assertFalse($child['method bar']['abstract']);

        $parentFoo = collect($this->symbol($artifact, 'Fixture\ApiE\Parent_')['methods'])->firstWhere('name', 'foo');
        $this->assertSame('public function foo(): string', $parentFoo['signature'], 'the linked owner publishes the declaration');
    }

    #[DataProvider('audiences')]
    public function test_an_abstract_requirement_from_an_unpublished_trait_does_not_hide_the_parent_implementation(string $audience): void
    {
        $artifact = $this->build($audience);
        $child = $this->inherited($this->symbol($artifact, 'Fixture\ApiE\ChildOfHidden'));

        $this->assertArrayHasKey('method foo', $child, 'the real inherited implementation is listed');
        $this->assertSame('Fixture\ApiE\Parent_', $child['method foo']['from']);
        $this->assertSame($audience === 'internal', $this->symbol($artifact, 'Fixture\ApiE\RequiresHidden') !== null, 'the trait is unpublished under public only');
    }

    #[DataProvider('audiences')]
    public function test_an_unmet_requirement_stays_an_abstract_requirement(string $audience): void
    {
        $needs = $this->inherited($this->symbol($this->build($audience), 'Fixture\ApiE\Needs'));

        $this->assertSame('Fixture\ApiE\RequiresPublished', $needs['method foo']['from']);
        $this->assertTrue($needs['method foo']['abstract'], 'no concrete implementation: still a requirement');
        $this->assertFalse($needs['method bar']['abstract']);
    }
}
