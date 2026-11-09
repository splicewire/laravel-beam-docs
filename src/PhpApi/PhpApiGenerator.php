<?php

namespace Splicewire\Beam\Docs\PhpApi;

use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Builds the PHP API reference artifact for the configured packages (php-api-reference slice 1). Build time only:
 * nothing here runs on a request, and package code is parsed, never loaded or executed.
 *
 * Steps: resolve the packages ({@see PhpApiPackages}); list each one's autoload source ({@see SourceRoots}); parse
 * every file statically ({@see SymbolCollector}); keep what the audience publishes; cross-link the effective inherited
 * members ({@see EffectiveMembers}: trait adaptations and nesting, parents; any own declaration shadows, and only
 * members reached through published symbols are listed, so nothing hidden leaks through inheritance; a parent outside
 * the set is plain text), implementers, and `@see` targets checked against published effective members; write the
 * per-package indexes, one file per symbol and the report ({@see ArtifactWriter}). Output is sorted and carries no
 * timestamp or absolute path, so a regeneration over the same input compares byte for byte.
 */
final class PhpApiGenerator
{
    public const SCHEMA = 'beam-docs.php-api/v1';

    public function __construct(
        private readonly PhpApiPackages $packages = new PhpApiPackages,
        private readonly SourceRoots $roots = new SourceRoots,
    ) {}

    /**
     * @param  array<int|string, mixed>  $configured  `beam.docs.php_api.packages`
     * @param  list<string>  $patterns  `beam.docs.php_api.references`
     * @return array{manifest: array<string, mixed>, indexes: array<string, array<string, mixed>>, symbols: array<string, array<string, mixed>>, report: array<string, mixed>}
     */
    public function build(array $configured, string $audience, array $patterns): array
    {
        $policy = new ProsePolicy($audience === 'public', $patterns);
        $parser = (new ParserFactory)->createForHostVersion();
        $packages = $this->packages->resolve($configured);
        $collected = [];
        $reports = [];

        foreach ($packages as $package) {
            $report = ['name' => $package['name'], 'status' => 'ok', 'problems' => [], 'parseErrors' => [], 'files' => 0,
                'published' => 0, 'hidden' => ['unmarked' => 0, 'internal' => 0, 'internalMembers' => 0, 'duplicate' => 0],
                'citationsStripped' => 0, 'withheld' => []];

            if ($package['path'] === null) {
                $report['status'] = 'unresolved';
                $report['problems'][] = match ($package['source']) {
                    'installed' => 'not installed in this process (list it as `vendor/name => path` for a package this host does not install)',
                    'invalid' => 'not a valid Composer package name',
                    default => 'the configured path is not a directory on this machine',
                };
                $reports[$package['name']] = $report;

                continue;
            }

            $roots = $this->roots->files($package['path']);
            $report['problems'] = $roots['problems'];
            $report['files'] = count($roots['files']);
            $digest = hash_init('sha256');

            foreach ($roots['files'] as $file) {
                $code = (string) @file_get_contents($package['path'].'/'.$file);
                hash_update($digest, $file."\0".hash('sha256', $code)."\n");

                try {
                    $ast = $parser->parse($code) ?? [];
                } catch (Error $e) {
                    $report['parseErrors'][] = ['file' => $file, 'line' => $e->getStartLine(), 'message' => $e->getRawMessage()];

                    continue;
                }

                $names = new NameResolver;
                $collector = new SymbolCollector($names, $file);
                $traverser = new NodeTraverser;
                $traverser->addVisitor($names);
                $traverser->addVisitor($collector);
                $traverser->traverse($ast);

                foreach ($collector->symbols as $symbol) {
                    if (isset($collected[$symbol['fqn']])) {
                        $report['hidden']['duplicate']++;

                        continue;
                    }

                    $symbol['package'] = $package['name'];
                    $collected[$symbol['fqn']] = $symbol;
                }
            }

            $report['sourceDigest'] = hash_final($digest);
            $reports[$package['name']] = $report;
        }

        // Audience: which symbols are published at all.
        $published = [];

        foreach ($collected as $fqn => $symbol) {
            $doc = $symbol['doc'];

            if ($doc->internal) {
                $reports[$symbol['package']]['hidden']['internal']++;
            } elseif ($policy->isPublic() && ! $doc->api) {
                $reports[$symbol['package']]['hidden']['unmarked']++;
            } else {
                $published[$fqn] = $symbol;
            }
        }

        ksort($published, SORT_STRING);
        $symbols = [];

        $effective = new EffectiveMembers($collected, $published);

        foreach ($published as $fqn => $symbol) {
            $symbols[$fqn] = $this->render($symbol, $published, $policy, $reports, $audience, $effective);
            $reports[$symbol['package']]['published']++;
        }

        $this->crossLink($symbols, $published, $effective);

        return (new ArtifactWriter)->shape($packages, $reports, $symbols, $audience, $configured, $patterns);
    }

    /**
     * One published symbol's artifact shape. Private and `@internal` members are never written (they still shadow, in
     * {@see EffectiveMembers}). Prose and tag descriptions take the audience policy; only the `internal` audience
     * carries the whole comment text.
     *
     * @param  array<string, mixed>  $symbol
     * @param  array<string, array<string, mixed>>  $published
     * @param  array<string, array<string, mixed>>  $reports  every package's report, by name
     * @return array<string, mixed>
     */
    private function render(array $symbol, array $published, ProsePolicy $policy, array &$reports, string $audience, EffectiveMembers $effective): array
    {
        $report = &$reports[$symbol['package']];

        // Prose under the audience policy. A withheld paragraph is reported where it must be REWRITTEN: by default this
        // symbol's file; for an adapted trait declaration (01b amend), its ORIGIN package's report, file and line, with
        // the consuming symbol and the effective (alias) member as identity. Never the text; never a private original's
        // name (`declaredIn` names the declaring symbol only when it is published).
        $prose = function (array $paragraphs, string $where, int $line, ?array $origin = null) use ($policy, &$reports, $symbol): array {
            $applied = $policy->apply($paragraphs);
            $target = $origin['package'] ?? $symbol['package'];
            $reports[$target]['citationsStripped'] += $applied['stripped'];

            foreach ($applied['withheld'] as $index) {
                $reports[$target]['withheld'][] = ['symbol' => $symbol['fqn'], 'member' => $where, 'paragraph' => $index, 'file' => $origin['file'] ?? $symbol['file'], 'line' => $line]
                    + ($origin !== null ? ['declaredIn' => $origin['declaredIn']] : []);
            }

            return $applied['kept'];
        };

        // A tag description is one paragraph under the same policy; a withheld one is dropped and reported by position.
        $tags = function (array $tags, string $where, int $line, ?array $origin = null) use ($prose): array {
            $out = [];

            foreach ($tags as $tag) {
                if (isset($tag['description'])) {
                    $kept = $prose([$tag['description']], trim($where.' '.$tag['tag'].(isset($tag['name']) ? ' '.$tag['name'] : '')), $line, $origin);
                    unset($tag['description']);

                    if ($kept !== []) {
                        $tag['description'] = $kept[0];
                    }
                }

                $out[] = $tag;
            }

            return $out;
        };

        $document = function (DocComment $doc, string $where, int $line, ?array $origin = null) use ($prose, $tags, $audience): array {
            return ['doc' => $prose($doc->paragraphs, $where, $line, $origin), 'tags' => $tags($doc->tags, $where, $line, $origin)]
                + ($audience === 'internal' ? ['comment' => $doc->text] : []);
        };

        $members = function (array $rows, string $kind) use ($document, &$report): array {
            $kept = [];

            foreach ($rows as $row) {
                if ($row['visibility'] === 'private') {
                    continue;
                }

                if ($row['doc']->internal) {
                    $report['hidden']['internalMembers']++;

                    continue;
                }

                $label = $kind === 'method' ? $row['name'].'()' : ($kind === 'property' ? '$'.$row['name'] : $row['name']);
                $doc = $row['doc'];
                unset($row['doc'], $row['sees']);
                $kept[] = $row + $document($doc, $label, $row['line']);
            }

            usort($kept, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

            return $kept;
        };

        // 01b: the inherited list, each adapted method (an alias or a visibility change) with its full EFFECTIVE
        // declaration: the effective name and visibility, the declaring member's parameters, return type, attributes
        // and audience-filtered docs. The private original itself is never written; an @internal one is never listed.
        // 01b amend: the resolved `@see` targets of each VISIBLE adapted declaration (resolved in its declaring file) join
        // this symbol's links; hidden originals and @internal aliases are never listed, so they contribute nothing.
        $adaptedSees = [];
        $inherited = array_map(function (array $row) use ($document, &$adaptedSees): array {
            $member = $row['member'] ?? null;
            $origin = $row['origin'] ?? null;
            unset($row['member'], $row['origin']);

            if ($member === null) {
                return $row;
            }

            array_push($adaptedSees, ...$member['sees']);
            $doc = $member['doc'];
            unset($member['doc'], $member['sees']);
            $member['name'] = $row['name'];
            $member['visibility'] = $row['visibility'];
            $member['signature'] = SymbolCollector::signature($row['name'], $row['visibility'], $member['abstract'], $member['final'], $member['static'], $member['byRef'], $member['params'], $member['returnType']);

            return $row + ['declaration' => $member + $document($doc, $row['name'].'()', $member['line'], $origin)];
        }, $effective->inherited($symbol['fqn']));

        $link = fn (string $fqn): array => ['fqn' => $fqn, 'published' => isset($published[$fqn])];

        // `@see` only from what is published: the symbol, its non-private non-internal methods, and its visible adapted
        // declarations.
        $sees = [...$symbol['sees'], ...$adaptedSees];

        foreach ($symbol['methods'] as $method) {
            if ($method['visibility'] !== 'private' && ! $method['doc']->internal) {
                array_push($sees, ...$method['sees']);
            }
        }

        $sees = array_values(array_unique($sees));
        sort($sees, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'fqn' => $symbol['fqn'],
            'package' => $symbol['package'],
            'kind' => $symbol['kind'],
            'modifiers' => $symbol['modifiers'],
            'templates' => $symbol['templates'],
            'extends' => array_map($link, $symbol['extends']),
            'implements' => array_map($link, $symbol['implements']),
            'traits' => array_map($link, $symbol['traits']),
            'backingType' => $symbol['backingType'],
            'attributes' => $symbol['attributes'],
        ] + $document($symbol['doc'], '', $symbol['line']) + [
            'file' => $symbol['file'],
            'line' => $symbol['line'],
            'constants' => $members($symbol['constants'], 'constant'),
            'cases' => $members($symbol['cases'], 'case'),
            'properties' => $members($symbol['properties'], 'property'),
            'methods' => $members($symbol['methods'], 'method'),
            'see' => $sees,
            'inherited' => $inherited,
            'implementers' => [],
        ];
    }

    /**
     * Second pass: inherited members ({@see EffectiveMembers}: traits with their adaptations, nested composition,
     * parents; listed only through published symbols, shadowed by any own declaration), `@see` targets (a class link is
     * published when the class is; a member link only when that member is a published effective member of a published
     * class) and implementers (published classes and enums whose own or published ancestors' interfaces name it).
     *
     * @param  array<string, array<string, mixed>>  $symbols
     * @param  array<string, array<string, mixed>>  $published
     */
    private function crossLink(array &$symbols, array $published, EffectiveMembers $effective): void
    {
        foreach ($symbols as $fqn => &$symbol) {
            $symbol['see'] = array_map(function (string $target) use ($published, $effective): array {
                [$class, $member] = array_pad(explode('::', $target, 2), 2, null);

                return ['target' => $target, 'published' => $member === null ? isset($published[$class]) : $effective->publishesMember($class, $member)];
            }, $symbol['see']);
        }
        unset($symbol);

        foreach ($symbols as $fqn => $symbol) {
            if (! in_array($symbol['kind'], ['class', 'enum'], true)) {
                continue;
            }

            $interfaces = [];
            $walk = $fqn;
            $seen = [];

            while ($walk !== null && isset($symbols[$walk]) && ! isset($seen[$walk])) {
                $seen[$walk] = true;

                foreach ($symbols[$walk]['implements'] as $link) {
                    $interfaces[] = $link['fqn'];
                }

                $walk = $symbols[$walk]['extends'][0]['fqn'] ?? null;
            }

            $closed = [];

            while ($interfaces !== []) {
                $i = array_shift($interfaces);

                if (isset($closed[$i])) {
                    continue;
                }

                $closed[$i] = true;

                if (isset($symbols[$i])) {
                    array_push($interfaces, ...array_map(fn (array $l): string => $l['fqn'], $symbols[$i]['extends']));
                }
            }

            foreach (array_keys($closed) as $interface) {
                if (isset($symbols[$interface]) && $symbols[$interface]['kind'] === 'interface') {
                    $symbols[$interface]['implementers'][] = $fqn;
                }
            }
        }

        foreach ($symbols as &$symbol) {
            $symbol['implementers'] = array_values(array_unique($symbol['implementers']));
            sort($symbol['implementers'], SORT_STRING);
        }
        unset($symbol);
    }
}
