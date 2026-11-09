<?php

namespace Splicewire\Beam\Docs\PhpApi;

/**
 * The effective members of each collected class-like, computed statically (php-api-reference slice 1, amended for
 * review REVISE fe468ff28 Spec 1, 2 and 7). No PHP is evaluated: this applies PHP's declared composition rules to the
 * collected declarations.
 *
 * - Own declarations come first and SHADOW anything inherited, whatever their visibility or `@internal` mark: a hidden
 *   override never lets the parent's member reappear, and its content is still never published.
 * - Traits are composed recursively (a trait's own traits included), in declaration order, applying `insteadof`
 *   (the named trait's method is excluded) and aliases (`m as alias`, `m as private`, `m as public alias`) with the
 *   resulting visibility; a trait's private methods take part, since an alias can make them public.
 * - A parent (or an interface's parents) contributes its non-private effective members.
 * - 01b (owner/root disposition 2026-11-09): an ABSTRACT trait method is a requirement, not an implementation: a
 *   concrete method from another trait or from the parent satisfies and replaces it (PHP precedence; {@see place()}),
 *   so the effective owner is the real implementation even when the trait is unpublished. An adapted method (an alias
 *   or a visibility change) keeps its declaring member, so its full effective declaration can be rendered; a private
 *   original's name is never listed.
 * - Every entry records the symbol that DECLARES it (`from`), an alias its `original` name, and whether it is LISTED:
 *   it reached here only through published symbols. A member that came through an unpublished parent or trait still
 *   shadows, but is never listed, so nothing unpublished leaks through inheritance. A parent outside the collected set
 *   contributes nothing (it is plain text).
 */
final class EffectiveMembers
{
    /** @var array<string, array<string, array<string, mixed>>> */
    private array $memo = [];

    /** @var array<string, true> */
    private array $visiting = [];

    /**
     * @param  array<string, array<string, mixed>>  $collected  every collected symbol, published or not
     * @param  array<string, mixed>  $published  the published symbols, by FQN
     */
    public function __construct(
        private readonly array $collected,
        private readonly array $published,
    ) {}

    /** @return list<array{kind: string, name: string, from: string, original?: string}> the listed inherited members */
    public function inherited(string $fqn): array
    {
        $rows = [];

        foreach ($this->of($fqn) as $entry) {
            if ($entry['from'] === $fqn || ! $this->visible($entry)) {
                continue;
            }

            $row = ['kind' => $entry['kind'], 'name' => $entry['name'], 'from' => $entry['from'], 'visibility' => $entry['visibility']];

            if ($entry['kind'] === 'method') {
                $row['abstract'] = $entry['abstract'];
            }

            // 01b: a private original's NAME is withheld like its content; a non-private one is named.
            if (isset($entry['original']) && $entry['declared'] !== 'private') {
                $row['original'] = $entry['original'];
            }

            // 01b: an adapted member (an alias, or a visibility change) is declared nowhere else as it is effective here,
            // so the generator renders its full effective declaration from the declaring member.
            if ($entry['adapted']) {
                $row['member'] = $entry['member'];
            }

            $rows[] = $row;
        }

        usort($rows, fn (array $a, array $b): int => [$a['kind'], $a['name']] <=> [$b['kind'], $b['name']]);

        return $rows;
    }

    /** Whether `Class::member()`, `Class::$prop` or `Class::NAME` is a published effective member of a published class. */
    public function publishesMember(string $class, string $member): bool
    {
        if (! isset($this->published[$class])) {
            return false;
        }

        $members = $this->of($class);
        $member = trim($member);

        if (str_starts_with($member, '$')) {
            $keys = ['property:'.substr($member, 1)];
        } elseif (str_ends_with($member, '()')) {
            $keys = ['method:'.strtolower(substr($member, 0, -2))];
        } else {
            $keys = ['constant:'.$member, 'case:'.$member, 'method:'.strtolower($member)];
        }

        foreach ($keys as $key) {
            if (isset($members[$key]) && $this->visible($members[$key])) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $entry */
    private function visible(array $entry): bool
    {
        return $entry['listed'] && $entry['visibility'] !== 'private' && ! $entry['internal'];
    }

    /** @return array<string, array<string, mixed>> keyed `kind:name` (method names case-insensitive) */
    private function of(string $fqn): array
    {
        if (isset($this->memo[$fqn])) {
            return $this->memo[$fqn];
        }

        if (! isset($this->collected[$fqn]) || isset($this->visiting[$fqn])) {
            return [];
        }

        $this->visiting[$fqn] = true;
        $symbol = $this->collected[$fqn];
        $out = [];

        foreach (['methods' => 'method', 'properties' => 'property', 'constants' => 'constant', 'cases' => 'case'] as $list => $kind) {
            foreach ($symbol[$list] as $member) {
                $out[self::key($kind, $member['name'])] = ['kind' => $kind, 'name' => $member['name'], 'from' => $fqn,
                    'visibility' => $member['visibility'], 'internal' => $member['doc']->internal, 'listed' => true,
                    'abstract' => $kind === 'method' && (bool) $member['abstract'], 'origin' => 'own',
                    'member' => $member, 'declared' => $member['visibility'], 'adapted' => false];
            }
        }

        $rules = $symbol['traitRules'];

        foreach ($symbol['traits'] as $trait) {
            $traitListed = isset($this->published[$trait]);

            foreach ($this->of($trait) as $entry) {
                $entry['listed'] = $entry['listed'] && $traitListed;
                $entry['origin'] = 'trait';

                if ($entry['kind'] !== 'method') {
                    $out[self::key($entry['kind'], $entry['name'])] ??= $entry;

                    continue;
                }

                $method = strtolower($entry['name']);
                $excluded = false;

                foreach ($rules['insteadof'] as $rule) {
                    if (strtolower($rule['method']) === $method && in_array($trait, $rule['instead'], true) && $rule['trait'] !== $trait) {
                        $excluded = true; // another trait's method is used instead of this one's, under the original name only
                    }
                }

                foreach ($rules['aliases'] as $rule) {
                    if (strtolower($rule['method']) !== $method || ($rule['trait'] !== null && $rule['trait'] !== $trait)) {
                        continue;
                    }

                    if ($rule['alias'] !== null) {
                        $alias = ['name' => $rule['alias'], 'visibility' => $rule['visibility'] ?? $entry['visibility'], 'original' => $entry['name'], 'adapted' => true] + $entry;
                        self::place($out, self::key('method', $rule['alias']), $alias);
                    } elseif ($rule['visibility'] !== null) {
                        $entry['visibility'] = $rule['visibility'];
                        $entry['adapted'] = true;
                    }
                }

                // An excluded method keeps its aliases (`A::m insteadof B; B::m as other;`) but not its own name.
                if (! $excluded) {
                    self::place($out, self::key('method', $entry['name']), $entry);
                }
            }
        }

        $parents = $symbol['kind'] === 'class' ? array_slice($symbol['extends'], 0, 1) : ($symbol['kind'] === 'interface' ? $symbol['extends'] : []);

        foreach ($parents as $parent) {
            $parentListed = isset($this->published[$parent]);

            foreach ($this->of($parent) as $key => $entry) {
                if ($entry['visibility'] === 'private') {
                    continue;
                }

                $entry['listed'] = $entry['listed'] && $parentListed;
                $entry['origin'] = 'parent';

                if ($entry['kind'] === 'method') {
                    self::place($out, $key, $entry);
                } else {
                    $out[$key] ??= $entry;
                }
            }
        }

        unset($this->visiting[$fqn]);

        return $this->memo[$fqn] = $out;
    }

    /**
     * PHP method precedence (01b): an own declaration wins; otherwise the first trait method wins over a parent's; but
     * an ABSTRACT trait method is only a requirement, so a concrete method (another trait's, or the parent's) satisfies
     * and replaces it. An abstract own declaration stays (the class redeclares the requirement itself).
     *
     * @param  array<string, array<string, mixed>>  $out
     * @param  array<string, mixed>  $entry
     */
    private static function place(array &$out, string $key, array $entry): void
    {
        $current = $out[$key] ?? null;

        if ($current === null || ($current['origin'] === 'trait' && $current['abstract'] && ! $entry['abstract'])) {
            $out[$key] = $entry;
        }
    }

    private static function key(string $kind, string $name): string
    {
        return $kind.':'.($kind === 'method' ? strtolower($name) : $name);
    }
}
