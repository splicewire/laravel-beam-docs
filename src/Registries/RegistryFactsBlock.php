<?php

namespace Splicewire\Beam\Docs\Registries;

use Rushing\Popcorn\Registries\Docs\RegistryFacts;
use Rushing\Popcorn\Registries\IsRegistry;

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
 * The block contract itself — the markers, {@see render()} and {@see extract()} — is popcorn's
 * {@see RegistryFacts}, which ticket 04's package-tier drift check reads; those four members only
 * delegate, so the writer and the checker can never render two different blocks. What stays here
 * is the writer's half: {@see stub()}, {@see replaceIn()} and the description seed.
 */
final class RegistryFactsBlock
{
    public const START = RegistryFacts::START;

    public const END = RegistryFacts::END;

    /** The block, markers included, for one declaration — {@see RegistryFacts::render()}. */
    public static function render(IsRegistry $declaration, string $declaringClass): string
    {
        return RegistryFacts::render($declaration, $declaringClass);
    }

    /** A fresh page: frontmatter, the facts block, the registrant panel, and an empty authored section seeded by the description. */
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
            self::panel($root),
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

    /**
     * The registrant panel tag (ticket 01, ruling 6): `@splicewire/beam-docs` renders it from the serving host's live
     * answer, so the page carries only the root. It sits outside the facts markers, in the authored part of the page:
     * a refresh never adds, moves or removes it, and an author may delete it. Roots are dotted identifiers, so the
     * attribute needs no escaping.
     */
    public static function panel(string $root): string
    {
        return '<RegistryRegistrants root="'.$root.'" />';
    }

    /**
     * `$contents` with the registrant panel for `$root` placed after its facts block, for an existing page
     * that predates the stub's panel (the scaffold command's `--panel`). Idempotent: a page that already
     * carries a `<RegistryRegistrants` tag anywhere (an author may have moved it) is returned unchanged.
     * Null when the page carries no well-formed facts block: a wholly hand-authored page, or a cluster
     * page, which spans several roots and so has no one root to show.
     *
     * The tag goes on its own line after `{/* registry-facts:end *\/}`, separated by one blank line on each
     * side, exactly where {@see stub()} puts it. Nothing between the markers changes, so the drift check
     * reads the same block; outside them, only the inserted line and its separating newlines are new.
     */
    public static function withPanel(string $contents, string $root): ?string
    {
        $start = strpos($contents, self::START);
        $end = $start === false ? false : strpos($contents, self::END, $start);

        if ($end === false) {
            return null;
        }

        if (preg_match('/<RegistryRegistrants\b/', $contents) === 1) {
            return $contents;
        }

        $at = $end + strlen(self::END);
        $rest = substr($contents, $at);
        $panel = "\n\n".self::panel($root);

        $tail = match (true) {
            $rest === '', $rest === "\n" => "\n",
            str_starts_with($rest, "\n\n") => $rest,
            str_starts_with($rest, "\n") => "\n".$rest,
            default => "\n\n".$rest,
        };

        return substr($contents, 0, $at).$panel.$tail;
    }

    /** The block as it stands in `$contents`, markers included, or null — {@see RegistryFacts::extract()}. */
    public static function extract(string $contents): ?string
    {
        return RegistryFacts::extract($contents);
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
