<?php

namespace Splicewire\Beam\Docs\PhpApi;

use PHPStan\PhpDocParser\Ast\PhpDoc\DeprecatedTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ExtendsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ImplementsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ThrowsTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\UsesTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;

/**
 * One docblock, read statically:
 * - its prose paragraphs (the text before the first tag, split on blank lines);
 * - whether it carries `@api` / `@internal`;
 * - its `@template` parameters with bound, default and variance;
 * - its structured tags (amended for review REVISE fe468ff28 Spec 4): `@param` / `@return` / `@var` / `@throws` /
 *   `@extends` / `@implements` / `@use` / `@deprecated`, each with its declared PHPDoc type as written and its
 *   description (the audience prose policy is applied to descriptions later, {@see ProsePolicy});
 * - its `@see` / `{@see}` targets (unresolved names; the collector resolves them in the file's name context);
 * - the whole comment's text (`/** *\/` and leading `*` removed), which only the internal audience publishes.
 *
 * Tags are read with phpstan/phpdoc-parser. Nothing is rewritten here.
 */
final class DocComment
{
    /**
     * @param  list<string>  $paragraphs
     * @param  list<array{name: string, bound: ?string, default: ?string, variance: string}>  $templates
     * @param  list<array<string, string>>  $tags
     * @param  list<string>  $sees
     */
    private function __construct(
        public readonly array $paragraphs,
        public readonly bool $api,
        public readonly bool $internal,
        public readonly array $templates,
        public readonly array $tags,
        public readonly array $sees,
        public readonly string $text,
    ) {}

    public static function none(): self
    {
        return new self([], false, false, [], [], [], '');
    }

    public static function parse(?string $raw): self
    {
        if ($raw === null || trim($raw) === '') {
            return self::none();
        }

        $text = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
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
        $templates = $tags = $sees = [];

        foreach (self::tags($raw) as $tag) {
            $name = strtolower($tag->name);
            $value = $tag->value;

            if ($name === '@api') {
                $api = true;
            } elseif ($name === '@internal') {
                $internal = true;
            } elseif ($value instanceof TemplateTagValueNode && in_array($name, ['@template', '@template-covariant', '@template-contravariant', '@phpstan-template', '@psalm-template'], true)) {
                $templates[] = [
                    'name' => $value->name,
                    'bound' => $value->bound !== null ? (string) $value->bound : null,
                    'default' => $value->default !== null ? (string) $value->default : null,
                    'variance' => match ($name) {
                        '@template-covariant' => 'covariant',
                        '@template-contravariant' => 'contravariant',
                        default => 'invariant',
                    },
                ];
            } elseif ($name === '@see') {
                $target = trim(strtok((string) $value, " \t") ?: '');

                if ($target !== '') {
                    $sees[] = $target;
                }
            } elseif (($structured = self::structured($tag->name, $value)) !== null) {
                $tags[] = $structured;
            }
        }

        if (preg_match_all('/\{@see\s+([^\s}]+)[^}]*\}/', $raw, $inline) > 0) {
            foreach ($inline[1] as $target) {
                $sees[] = $target;
            }
        }

        return new self($paragraphs, $api, $internal, $templates, $tags, array_values(array_unique($sees)), trim(implode("\n", $text)));
    }

    /** One documented tag as a stable shape (key order fixed), or null for a tag this artifact does not carry. */
    private static function structured(string $tag, mixed $value): ?array
    {
        $describe = fn (array $row, string $description): array => trim($description) === '' ? $row : $row + ['description' => trim($description)];

        return match (true) {
            $value instanceof ParamTagValueNode => $describe(['tag' => $tag, 'type' => (string) $value->type, 'name' => ($value->isVariadic ? '...' : '').$value->parameterName], $value->description),
            $value instanceof ReturnTagValueNode => $describe(['tag' => $tag, 'type' => (string) $value->type], $value->description),
            $value instanceof VarTagValueNode => $describe(['tag' => $tag, 'type' => (string) $value->type] + ($value->variableName !== '' ? ['name' => $value->variableName] : []), $value->description),
            $value instanceof ThrowsTagValueNode => $describe(['tag' => $tag, 'type' => (string) $value->type], $value->description),
            $value instanceof ExtendsTagValueNode, $value instanceof ImplementsTagValueNode, $value instanceof UsesTagValueNode => $describe(['tag' => $tag, 'type' => (string) $value->type], $value->description),
            $value instanceof DeprecatedTagValueNode => $describe(['tag' => $tag], $value->description),
            default => null,
        };
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
