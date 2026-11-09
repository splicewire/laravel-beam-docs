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
 * parents, interfaces, traits, attributes, docblock, constants, enum cases, properties (constructor-promoted included)
 * and methods with their complete declared signatures, and the source file and line. Runs AFTER {@see NameResolver},
 * so every name is fully qualified; `@see` targets are resolved here in the file's own name context.
 *
 * Private members are never collected. Package code is never loaded or executed: this reads the AST only.
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
            'traits' => $this->traits($node),
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
            if ($stmt instanceof Stmt\ClassConst && ! $stmt->isPrivate()) {
                foreach ($stmt->consts as $const) {
                    $symbol['constants'][] = [
                        'name' => $const->name->toString(),
                        'visibility' => $stmt->isProtected() ? 'protected' : 'public',
                        'final' => $stmt->isFinal(),
                        'type' => $stmt->type !== null ? $this->type($stmt->type) : null,
                        'value' => $this->printer->prettyPrintExpr($const->value),
                        'doc' => DocComment::parse($stmt->getDocComment()?->getText()),
                        'line' => $const->getStartLine(),
                    ];
                }
            } elseif ($stmt instanceof Stmt\EnumCase) {
                $symbol['cases'][] = [
                    'name' => $stmt->name->toString(),
                    'value' => $stmt->expr !== null ? $this->printer->prettyPrintExpr($stmt->expr) : null,
                    'doc' => DocComment::parse($stmt->getDocComment()?->getText()),
                    'line' => $stmt->getStartLine(),
                ];
            } elseif ($stmt instanceof Stmt\Property && ! $stmt->isPrivate()) {
                foreach ($stmt->props as $prop) {
                    $symbol['properties'][] = [
                        'name' => $prop->name->toString(),
                        'visibility' => $stmt->isProtected() ? 'protected' : 'public',
                        'static' => $stmt->isStatic(),
                        'readonly' => $stmt->isReadonly(),
                        'type' => $stmt->type !== null ? $this->type($stmt->type) : null,
                        'default' => $prop->default !== null ? $this->printer->prettyPrintExpr($prop->default) : null,
                        'promoted' => false,
                        'doc' => DocComment::parse($stmt->getDocComment()?->getText()),
                        'line' => $prop->getStartLine(),
                    ];
                }
            } elseif ($stmt instanceof Stmt\ClassMethod && ! $stmt->isPrivate()) {
                $symbol['methods'][] = $this->method($stmt, $kind);

                if ($stmt->name->toLowerString() === '__construct') {
                    foreach ($stmt->params as $param) {
                        if ($param->flags !== 0 && ($param->flags & Modifiers::PRIVATE) === 0) {
                            $symbol['properties'][] = [
                                'name' => (string) $param->var->name,
                                'visibility' => ($param->flags & Modifiers::PROTECTED) !== 0 ? 'protected' : 'public',
                                'static' => false,
                                'readonly' => ($param->flags & Modifiers::READONLY) !== 0,
                                'type' => $param->type !== null ? $this->type($param->type) : null,
                                'default' => null,
                                'promoted' => true,
                                'doc' => DocComment::parse($param->getDocComment()?->getText()),
                                'line' => $param->getStartLine(),
                            ];
                        }
                    }
                }
            }
        }

        $this->symbols[] = $symbol;

        return null;
    }

    /** @return array<string, mixed> */
    private function method(Stmt\ClassMethod $method, string $kind): array
    {
        $doc = DocComment::parse($method->getDocComment()?->getText());
        $visibility = $method->isProtected() ? 'protected' : 'public';
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
        $signature = implode(' ', array_filter([
            $method->isAbstract() && $kind !== 'interface' ? 'abstract' : null,
            $method->isFinal() ? 'final' : null,
            $visibility,
            $method->isStatic() ? 'static' : null,
            'function',
        ])).' '.($method->byRef ? '&' : '').$method->name->toString().'('.implode(', ', array_map(
            fn (array $p): string => trim(($p['type'] ?? '').' '.($p['byRef'] ? '&' : '').($p['variadic'] ? '...' : '').'$'.$p['name'].($p['default'] !== null ? ' = '.$p['default'] : '')),
            $params,
        )).')'.($return !== null ? ': '.$return : '');

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

    /** @return list<string> */
    private function traits(Stmt\ClassLike $node): array
    {
        $traits = [];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traits[] = $trait->toString();
                }
            }
        }

        return $traits;
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
