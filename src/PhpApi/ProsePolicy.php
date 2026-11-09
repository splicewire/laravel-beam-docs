<?php

namespace Splicewire\Beam\Docs\PhpApi;

/**
 * The owner's docblock ruling (2026-11-06), applied at GENERATION only; source comments are never rewritten.
 *
 * Under `public`: a parenthetical that contains a reference is removed and a leading reference tag is removed, the
 * sentence kept (items 1); a paragraph that STILL contains a reference is withheld from the artifact and counted
 * for the report (item 2). Under `internal`: every paragraph is kept whole.
 *
 * "A reference" is any match of the configured `beam.docs.php_api.references` patterns (tickets, ADRs, rulings, map
 * slugs). The report records WHERE a paragraph was withheld, never its text, so no reference reaches the artifact.
 */
final class ProsePolicy
{
    /** @param  list<string>  $patterns  PCRE patterns */
    public function __construct(
        private readonly bool $public,
        private readonly array $patterns,
    ) {}

    public function isPublic(): bool
    {
        return $this->public;
    }

    /**
     * @param  list<string>  $paragraphs
     * @return array{kept: list<string>, stripped: int, withheld: list<int>} withheld = original paragraph indexes
     */
    public function apply(array $paragraphs): array
    {
        if (! $this->public) {
            return ['kept' => $paragraphs, 'stripped' => 0, 'withheld' => []];
        }

        $kept = [];
        $withheld = [];
        $stripped = 0;

        foreach ($paragraphs as $index => $paragraph) {
            $text = $paragraph;

            // Item 1a: a parenthetical (innermost first) whose content is or contains a reference.
            do {
                $before = $text;
                $text = (string) preg_replace_callback('/\s*\(([^()]*)\)/', function (array $m) use (&$stripped): string {
                    if ($this->mentionsReference($m[1])) {
                        $stripped++;

                        return '';
                    }

                    return $m[0];
                }, $text);
            } while ($text !== $before);

            // Item 1b: a leading tag (`APP-09b:`, `[ruling T23]`, `ticket 12 —`) that is a reference.
            if (preg_match('/^\s*(?:\[([^\]\n]{1,80})\]|([^:\n—–]{1,80}?)\s*(?::|—|–))\s+/u', $text, $lead) === 1
                && $this->mentionsReference(($lead[1] ?? '') !== '' ? $lead[1] : ($lead[2] ?? ''))) {
                $text = substr($text, strlen($lead[0]));
                $stripped++;
            }

            $text = trim((string) preg_replace('/[ \t]+([,.;:])/', '$1', $text));

            if ($text === '') {
                continue;
            }

            // Item 2: still a reference somewhere in the paragraph: withheld, reported by position.
            if ($this->mentionsReference($text)) {
                $withheld[] = $index;

                continue;
            }

            $kept[] = $text;
        }

        return ['kept' => $kept, 'stripped' => $stripped, 'withheld' => $withheld];
    }

    public function mentionsReference(string $text): bool
    {
        foreach ($this->patterns as $pattern) {
            if (@preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
