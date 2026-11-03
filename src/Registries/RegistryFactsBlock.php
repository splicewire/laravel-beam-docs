<?php

namespace Splicewire\Beam\Docs\Registries;

use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\OnKeyDuplicate;
use Rushing\Popcorn\Registries\PopulationRequirement;

/**
 * The generated-facts block of a registry page (registry-docs ticket 01, ruling 6): DECLARATION
 * FACTS ONLY — root, entry contract, duplicate policy, population requirement, order and the
 * declaring class — rendered from the `#[IsRegistry]` attribute, delimited by markers so the
 * scaffold command can rewrite it in place without touching a byte of authored prose.
 *
 * The baked `description` is deliberately NOT a fact: it is authorial input (R10), written for
 * maintainers and often in agent register. {@see stub()} offers it to the author as a seed inside an
 * MDX comment, which compiles to nothing; nothing here ever renders it.
 *
 * The markers are MDX expression comments (`{/* … *\/}`), not HTML comments: MDX 3 — what
 * `@splicewire/beam-mdx` compiles with — rejects `<!-- -->` as a syntax error.
 *
 * Framework-free on purpose (plain PHP over popcorn's attribute): ticket 04's package-tier drift
 * check is to run in framework-free `rushing/php-popcorn`, and the renderer must be liftable there
 * as-is so both sides render byte-identical blocks.
 */
final class RegistryFactsBlock
{
    public const START = '{/* registry-facts:start — generated from the #[IsRegistry] declaration by `splicewire:beam:docs:registries:scaffold`. Rerun the command; do not edit between these markers. */}';

    public const END = '{/* registry-facts:end */}';

    /** The block, markers included, for one declaration. */
    public static function render(IsRegistry $declaration, string $declaringClass): string
    {
        $rows = [
            ['Root', self::code($declaration->root)],
            ['Entries', $declaration->entryType === 'mixed' ? 'any value (`mixed`)' : self::code(ltrim($declaration->entryType, '\\'))],
            ['Duplicate keys', self::duplicates($declaration->onKeyDuplicate)],
            ['Population', self::population($declaration->populationRequirement)],
            ['Order', self::code((string) $declaration->order).' (display order, ascending)'],
            ['Declared by', self::code(ltrim($declaringClass, '\\'))],
        ];

        // A CommonMark list, not a GFM table: the hosts' MDX pipelines run no remark-gfm, so a table
        // would render as a paragraph of pipes.
        $lines = [self::START, ''];

        foreach ($rows as [$fact, $value]) {
            $lines[] = "- **{$fact}:** {$value}";
        }

        $lines[] = '';
        $lines[] = self::END;

        return implode("\n", $lines);
    }

    /** A fresh page: frontmatter, the facts block, and an empty authored section seeded by the description. */
    public static function stub(IsRegistry $declaration, string $declaringClass): string
    {
        $root = $declaration->root;
        $seed = self::seed($declaration->description);

        return implode("\n", [
            '---',
            "title: {$root}",
            "segment: {$root}",
            "nav_order: {$declaration->order}",
            '---',
            '',
            "# `{$root}`",
            '',
            self::render($declaration, $declaringClass),
            '',
            '{/* registry-prose — authored. Everything outside the facts markers is yours; the scaffold command never writes it.',
            '   Write for a developer meeting this registry cold: what it is for, who registers into it, how to register,',
            '   and what happens on a duplicate. Delete this comment when the page is written.'.($seed === null ? '' : "\n\n   Seed — the declaration's baked description (authorial input; it is never rendered):\n\n".$seed),
            '*/}',
            '',
        ]);
    }

    /**
     * `$contents` with its facts block replaced by `$block`, or null when it carries no well-formed
     * block (an authored page the command must leave alone). Only the bytes between and including
     * the markers change.
     */
    public static function replaceIn(string $contents, string $block): ?string
    {
        $start = strpos($contents, self::START);

        if ($start === false) {
            return null;
        }

        $end = strpos($contents, self::END, $start);

        if ($end === false) {
            return null;
        }

        return substr($contents, 0, $start).$block.substr($contents, $end + strlen(self::END));
    }

    /** The block as it stands in `$contents`, markers included, or null — the drift check's reader. */
    public static function extract(string $contents): ?string
    {
        $start = strpos($contents, self::START);
        $end = $start === false ? false : strpos($contents, self::END, $start);

        return $start === false || $end === false ? null : substr($contents, $start, $end + strlen(self::END) - $start);
    }

    private static function duplicates(OnKeyDuplicate $policy): string
    {
        return match ($policy) {
            OnKeyDuplicate::Supersede => 'Supersede — a later registration under a taken key replaces the earlier entry, in its place',
            OnKeyDuplicate::Reject => 'Reject — registering a taken key throws',
            OnKeyDuplicate::Admit => 'Admit — every registration is kept; a single-entry read of a shared key refuses as ambiguous',
        };
    }

    private static function population(PopulationRequirement $requirement): string
    {
        return match ($requirement) {
            PopulationRequirement::Required => 'Required — an empty registry is reported as unpopulated',
            PopulationRequirement::Optional => 'Optional — an empty registry is normal',
        };
    }

    private static function code(string $text): string
    {
        return '`'.str_replace('`', "'", $text).'`';
    }

    /**
     * The description, made safe to sit inside an MDX comment expression: `*\/` would close the
     * comment, and micromark counts braces to find the expression's end, so braces become
     * parentheses (`{@see X}` reads as `X`). Wrapped to an indented paragraph for the author.
     */
    private static function seed(?string $description): ?string
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        $text = preg_replace('/\{@see\s+([^}]+)\}/', '$1', $description) ?? $description;
        $text = strtr($text, ['*/' => '* /', '{' => '(', '}' => ')']);

        return '   '.wordwrap(trim((string) preg_replace('/\s+/', ' ', $text)), 100, "\n   ");
    }
}
