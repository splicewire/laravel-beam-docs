<?php

namespace Splicewire\Beam\Docs\PhpApi;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

/**
 * Collects every named class-like in one parsed file (php-api-reference slice 1): its kind, modifiers, templates,
 * parents, interfaces, traits WITH their adaptations (`insteadof`, aliases, visibility changes), attributes, docblock,
 * and EVERY declared constant, enum case, property (constructor-promoted included, whatever the constructor's own
 * visibility) and method with its complete declared signature, its attributes and its source line.
 *
 * Private and `@internal` members are collected too, marked by `visibility` and their docblock: the generator never
 * publishes them, but needs them as SHADOWS, so a hidden override never lets the parent's member reappear and a
 * private trait method can still be aliased public (amended for review REVISE fe468ff28, Spec 1-3 and 6).
 *
 * Runs AFTER {@see NameResolver}, so every name is fully qualified; `@see` targets are resolved here in the file's own
 * name context. Package code is never loaded or executed: this reads the AST only.
 */
final class SymbolCollector extends NodeVisitorAbstract
{
    /** @var list<array<string, mixed>> */
    public array $symbols = [];

    private readonly Standard $printer;

    public function __construct(
        private readonly NameResolver $names,
        private readonly string $file,
    ) {
        $this->printer = new Standard;
    }

    /**
     * On LEAVE, not enter: {@see NameResolver} resolves a class-like's inner names (trait uses, parameter and return
     * types) as the traversal descends into them, so only after the children have been visited is every name
     * fully qualified. The file's name context still holds here.
     */
    public function leaveNode(Node $node): null
    {
        if (! $node instanceof Stmt\ClassLike || $node->name === null || ! isset($node->namespacedName)) {
            return null;
        }

        $doc = DocComment::parse($node->getDocComment()?->getText());
        $kind = match (true) {
            $node instanceof Stmt\Interface_ => 'interface',
            $node instanceof Stmt\Trait_ => 'trait',
            $node instanceof Stmt\Enum_ => 'enum',
            default => 'class',
        };

        $symbol = [
            'fqn' => $node->namespacedName->toString(),
            'kind' => $kind,
            'modifiers' => $node instanceof Stmt\Class_ ? $this->classModifiers($node) : [],
            'templates' => $doc->templates,
            'extends' => $this->parents($node),
            'implements' => $this->interfaces($node),
            'traits' => [],
            'traitRules' => ['insteadof' => [], 'aliases' => []],
            'backingType' => $node instanceof Stmt\Enum_ && $node->scalarType !== null ? $this->type($node->scalarType) : null,
            'attributes' => $this->attributes($node->attrGroups),
            'doc' => $doc,
            'sees' => $this->resolveSees($doc->sees),
            'file' => $this->file,
            'line' => $node->getStartLine(),
            'constants' => [],
            'cases' => [],
            'properties' => [],
            'methods' => [],
        ];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                $this->traitUse($stmt, $symbol);
            } elseif ($stmt instanceof Stmt\ClassConst) {
                foreach ($stmt->consts as $const) {
                    $symbol['constants'][] = [
                        'name' => $const->name->toString(),
                        'visibility' => $this->visibility($stmt->flags),
                        'final' => $stmt->isFinal(),
                        'type' => $stmt->type !== null ? $this->type($stmt->type) : null,
                        'value' => $this->printer->prettyPrintExpr($const->value),
                        'attributes' => $this->attributes($stmt->attrGroups),
                        'doc' => DocComment::parse($stmt->getDocComment()?->getText()),
                        'line' => $const->getStartLine(),
                    ];
                }
            } elseif ($stmt instanceof Stmt\EnumCase) {
                $symbol['cases'][] = [
                    'name' => $stmt->name->toString(),
                    'visibility' => 'public',
                    'value' => $stmt->expr !== null ? $this->printer->prettyPrintExpr($stmt->expr) : null,
                    'attributes' => $this->attributes($stmt->attrGroups),
                    'doc' => DocComment::parse($stmt->getDocComment()?->getText()),
                    'line' => $stmt->getStartLine(),
                ];
            } elseif ($stmt instanceof Stmt\Property) {
                foreach ($stmt->props as $prop) {
                    $symbol['properties'][] = [
                        'name' => $prop->name->toString(),
                        'visibility' => $this->visibility($stmt->flags),
                        'static' => $stmt->isStatic(),
                        'readonly' => $stmt->isReadonly(),
                        'type' => $stmt->type !== null ? $this->type($stmt->type) : null,
                        'default' => $prop->default !== null ? $this->printer->prettyPrintExpr($prop->default) : null,
                        'promoted' => false,
                        'attributes' => $this->attributes($stmt->attrGroups),
                        'doc' => DocComment::parse($stmt->getDocComment()?->getText()),
                        'line' => $prop->getStartLine(),
                    ];
                }
            } elseif ($stmt instanceof Stmt\ClassMethod) {
                $symbol['methods'][] = $this->method($stmt, $kind);

                // Spec 3: promotion is a property declaration of its own, whatever the constructor's visibility.
                if ($stmt->name->toLowerString() === '__construct') {
                    foreach ($stmt->params as $param) {
                        if ($param->flags === 0) {
                            continue;
                        }

                        $symbol['properties'][] = [
                            'name' => (string) $param->var->name,
                            'visibility' => $this->visibility($param->flags),
                            'static' => false,
                            'readonly' => ($param->flags & Modifiers::READONLY) !== 0,
                            'type' => $param->type !== null ? $this->type($param->type) : null,
                            'default' => null,
                            'promoted' => true,
                            'attributes' => $this->attributes($param->attrGroups),
                            'doc' => DocComment::parse($param->getDocComment()?->getText()),
                            'line' => $param->getStartLine(),
                        ];
                    }
                }
            }
        }

        $this->symbols[] = $symbol;

        return null;
    }

    /** A trait use: the traits, then its `insteadof` and alias rules, names resolved. */
    private function traitUse(Stmt\TraitUse $use, array &$symbol): void
    {
        foreach ($use->traits as $trait) {
            $symbol['traits'][] = $trait->toString();
        }

        foreach ($use->adaptations as $rule) {
            if ($rule instanceof Stmt\TraitUseAdaptation\Precedence) {
                $symbol['traitRules']['insteadof'][] = [
                    'trait' => $rule->trait?->toString(),
                    'method' => $rule->method->toString(),
                    'instead' => array_map(fn (Node\Name $n): string => $n->toString(), $rule->insteadof),
                ];
            } elseif ($rule instanceof Stmt\TraitUseAdaptation\Alias) {
                $symbol['traitRules']['aliases'][] = [
                    'trait' => $rule->trait?->toString(),
                    'method' => $rule->method->toString(),
                    'alias' => $rule->newName?->toString(),
                    'visibility' => $rule->newModifier !== null ? $this->visibility($rule->newModifier) : null,
                ];
            }
        }
    }

    private function visibility(int $flags): string
    {
        return match (true) {
            ($flags & Modifiers::PRIVATE) !== 0 => 'private',
            ($flags & Modifiers::PROTECTED) !== 0 => 'protected',
            default => 'public',
        };
    }

    /**
     * A method's declared signature, from its parts. One builder for a declaration and for an adapted trait method
     * (01b: an alias or a visibility change is rebuilt with its effective name and visibility, never string-patched).
     *
     * @param  list<array<string, mixed>>  $params
     */
    public static function signature(string $name, string $visibility, bool $abstract, bool $final, bool $static, bool $byRef, array $params, ?string $return): string
    {
        return implode(' ', array_filter([
            $abstract ? 'abstract' : null,
            $final ? 'final' : null,
            $visibility,
            $static ? 'static' : null,
            'function',
        ])).' '.($byRef ? '&' : '').$name.'('.implode(', ', array_map(
            fn (array $p): string => trim(($p['type'] ?? '').' '.($p['byRef'] ? '&' : '').($p['variadic'] ? '...' : '').'$'.$p['name'].($p['default'] !== null ? ' = '.$p['default'] : '')),
            $params,
        )).')'.($return !== null ? ': '.$return : '');
    }

    /** @return array<string, mixed> */
    private function method(Stmt\ClassMethod $method, string $kind): array
    {
        $doc = DocComment::parse($method->getDocComment()?->getText());
        $visibility = $this->visibility($method->flags);
        $params = [];

        foreach ($method->params as $param) {
            $params[] = [
                'name' => (string) $param->var->name,
                'type' => $param->type !== null ? $this->type($param->type) : null,
                'default' => $param->default !== null ? $this->printer->prettyPrintExpr($param->default) : null,
                'byRef' => $param->byRef,
                'variadic' => $param->variadic,
                'promoted' => $param->flags !== 0,
                'attributes' => $this->attributes($param->attrGroups),
            ];
        }

        $return = $method->returnType !== null ? $this->type($method->returnType) : null;
        $signature = self::signature($method->name->toString(), $visibility, $method->isAbstract() && $kind !== 'interface', $method->isFinal(), $method->isStatic(), $method->byRef, $params, $return);

        return [
            'name' => $method->name->toString(),
            'visibility' => $visibility,
            'static' => $method->isStatic(),
            'abstract' => $method->isAbstract() || $kind === 'interface',
            'final' => $method->isFinal(),
            'byRef' => $method->byRef,
            'params' => $params,
            'returnType' => $return,
            'templates' => $doc->templates,
            'attributes' => $this->attributes($method->attrGroups),
            'signature' => $signature,
            'doc' => $doc,
            'sees' => $this->resolveSees($doc->sees),
            'line' => $method->getStartLine(),
        ];
    }

    /** @return list<string> */
    private function classModifiers(Stmt\Class_ $class): array
    {
        return array_values(array_filter([
            $class->isAbstract() ? 'abstract' : null,
            $class->isFinal() ? 'final' : null,
            $class->isReadonly() ? 'readonly' : null,
        ]));
    }

    /** @return list<string> */
    private function parents(Stmt\ClassLike $node): array
    {
        if ($node instanceof Stmt\Class_) {
            return $node->extends !== null ? [$node->extends->toString()] : [];
        }

        if ($node instanceof Stmt\Interface_) {
            return array_map(fn (Node\Name $n): string => $n->toString(), $node->extends);
        }

        return [];
    }

    /** @return list<string> */
    private function interfaces(Stmt\ClassLike $node): array
    {
        if ($node instanceof Stmt\Class_ || $node instanceof Stmt\Enum_) {
            return array_map(fn (Node\Name $n): string => $n->toString(), $node->implements);
        }

        return [];
    }

    /**
     * @param  list<Node\AttributeGroup>  $groups
     * @return list<array{name: string, arguments: list<string>}>
     */
    private function attributes(array $groups): array
    {
        $attributes = [];

        foreach ($groups as $group) {
            foreach ($group->attrs as $attr) {
                $attributes[] = [
                    'name' => $attr->name->toString(),
                    'arguments' => array_map(fn (Node\Arg $arg): string => ($arg->name !== null ? $arg->name->toString().': ' : '').$this->printer->prettyPrintExpr($arg->value), $attr->args),
                ];
            }
        }

        return $attributes;
    }

    private function type(Node $type): string
    {
        return match (true) {
            $type instanceof Node\Identifier => $type->toString(),
            $type instanceof Node\Name => $type->toString(),
            $type instanceof Node\NullableType => '?'.$this->type($type->type),
            $type instanceof Node\UnionType => implode('|', array_map(fn (Node $t): string => $t instanceof Node\IntersectionType ? '('.$this->type($t).')' : $this->type($t), $type->types)),
            $type instanceof Node\IntersectionType => implode('&', array_map(fn (Node $t): string => $this->type($t), $type->types)),
            default => $this->printer->prettyPrint([$type]),
        };
    }

    /**
     * `@see` targets in this file's name context: `Class`, `Class::member()` or `Class::$prop`. A target that is not a
     * class name (a URL, a bare function) is kept as written.
     *
     * @param  list<string>  $sees
     * @return list<string>
     */
    private function resolveSees(array $sees): array
    {
        $resolved = [];

        foreach ($sees as $see) {
            [$class, $member] = array_pad(explode('::', $see, 2), 2, null);

            if (preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*$/', (string) $class) !== 1) {
                $resolved[] = $see;

                continue;
            }

            $name = str_starts_with((string) $class, '\\')
                ? ltrim((string) $class, '\\')
                : $this->names->getNameContext()->getResolvedClassName(new Node\Name((string) $class))->toString();

            $resolved[] = $member !== null ? $name.'::'.$member : $name;
        }

        return $resolved;
    }
}
