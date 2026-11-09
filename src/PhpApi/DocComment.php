<?php

namespace Splicewire\Beam\Docs\PhpApi;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;

/**
 * One docblock, read statically: its prose paragraphs (the text before the first tag, split on blank lines), whether
 * it carries `@api` / `@internal`, its `@template` parameters and its `@see` / `{@see}` targets (unresolved names;
 * the collector resolves them in the file's name context). Tags are read with phpstan/phpdoc-parser; prose is the
 * comment's own text, so nothing is rewritten here (the audience policy is {@see ProsePolicy}).
 */
final class DocComment
{
    /**
     * @param  list<string>  $paragraphs
     * @param  list<array{name: string, bound: ?string}>  $templates
     * @param  list<string>  $sees
     */
    private function __construct(
        public readonly array $paragraphs,
        public readonly bool $api,
        public readonly bool $internal,
        public readonly array $templates,
        public readonly array $sees,
    ) {}

    public static function none(): self
    {
        return new self([], false, false, [], []);
    }

    public static function parse(?string $raw): self
    {
        if ($raw === null || trim($raw) === '') {
            return self::none();
        }

        $lines = preg_split('/\R/', $raw) ?: [];
        $text = [];

        foreach ($lines as $line) {
            $line = preg_replace('#^\s*/\*\*#', '', $line);
            $line = preg_replace('#\*/\s*$#', '', (string) $line);
            $line = preg_replace('#^\s*\* ?#', '', (string) $line);
            $text[] = rtrim((string) $line);
        }

        $prose = [];

        foreach ($text as $line) {
            if (preg_match('/^\s*@[a-zA-Z]/', $line) === 1) {
                break;
            }

            $prose[] = $line;
        }

        $paragraphs = [];

        foreach (preg_split('/\n\s*\n/', trim(implode("\n", $prose))) ?: [] as $paragraph) {
            $paragraph = trim((string) $paragraph);

            if ($paragraph !== '') {
                $paragraphs[] = $paragraph;
            }
        }

        $api = $internal = false;
        $templates = $sees = [];

        foreach (self::tags($raw) as $tag) {
            $name = strtolower($tag->name);

            if ($name === '@api') {
                $api = true;
            } elseif ($name === '@internal') {
                $internal = true;
            } elseif (in_array($name, ['@template', '@template-covariant', '@template-contravariant', '@phpstan-template'], true) && $tag->value instanceof TemplateTagValueNode) {
                $templates[] = ['name' => $tag->value->name, 'bound' => $tag->value->bound !== null ? (string) $tag->value->bound : null];
            } elseif ($name === '@see') {
                $target = trim(strtok((string) $tag->value, " \t") ?: '');

                if ($target !== '') {
                    $sees[] = $target;
                }
            }
        }

        if (preg_match_all('/\{@see\s+([^\s}]+)[^}]*\}/', $raw, $inline) > 0) {
            foreach ($inline[1] as $target) {
                $sees[] = $target;
            }
        }

        return new self($paragraphs, $api, $internal, $templates, array_values(array_unique($sees)));
    }

    /** @return list<PhpDocTagNode> */
    private static function tags(string $raw): array
    {
        try {
            $config = new ParserConfig([]);
            $constExpr = new ConstExprParser($config);
            $parser = new PhpDocParser($config, new TypeParser($config, $constExpr), $constExpr);
            $node = $parser->parse(new TokenIterator((new Lexer($config))->tokenize($raw)));

            return $node->getTags();
        } catch (Throwable) {
            return [];
        }
    }
}
