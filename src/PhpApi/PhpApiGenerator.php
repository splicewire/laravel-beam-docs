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
 * every file statically ({@see SymbolCollector}); keep what the audience publishes; cross-link inherited members,
 * implementers and `@see` targets within the published set (a parent outside it is plain text with no inherited list,
 * including a package's own unpublished symbol, so nothing hidden leaks through inheritance); write the manifest, the
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

        foreach ($published as $fqn => $symbol) {
            $symbols[$fqn] = $this->render($symbol, $published, $policy, $reports[$symbol['package']]);
            $reports[$symbol['package']]['published']++;
        }

        $this->crossLink($symbols, $published);

        return (new ArtifactWriter)->shape($packages, $reports, $symbols, $audience, $configured, $patterns);
    }

    /**
     * @param  array<string, mixed>  $symbol
     * @param  array<string, array<string, mixed>>  $published
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function render(array $symbol, array $published, ProsePolicy $policy, array &$report): array
    {
        $prose = function (DocComment $doc, string $where, int $line) use ($policy, &$report, $symbol): array {
            $applied = $policy->apply($doc->paragraphs);
            $report['citationsStripped'] += $applied['stripped'];

            foreach ($applied['withheld'] as $index) {
                $report['withheld'][] = ['symbol' => $symbol['fqn'], 'member' => $where, 'paragraph' => $index, 'file' => $symbol['file'], 'line' => $line];
            }

            return $applied['kept'];
        };

        $members = function (array $rows, string $kind) use ($prose, &$report): array {
            $kept = [];

            foreach ($rows as $row) {
                if ($row['doc']->internal) {
                    $report['hidden']['internalMembers']++;

                    continue;
                }

                $label = $kind === 'method' ? $row['name'].'()' : ($kind === 'property' ? '$'.$row['name'] : $row['name']);
                $row['doc'] = $prose($row['doc'], $label, $row['line']);
                unset($row['sees']);
                $kept[] = $row;
            }

            usort($kept, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

            return $kept;
        };

        $link = fn (string $fqn): array => ['fqn' => $fqn, 'published' => isset($published[$fqn])];
        $sees = array_merge($symbol['sees'], ...array_map(fn (array $m): array => $m['sees'], $symbol['methods']));
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
            'doc' => $prose($symbol['doc'], '', $symbol['line']),
            'file' => $symbol['file'],
            'line' => $symbol['line'],
            'constants' => $members($symbol['constants'], 'constant'),
            'cases' => $members($symbol['cases'], 'case'),
            'properties' => $members($symbol['properties'], 'property'),
            'methods' => $members($symbol['methods'], 'method'),
            'see' => array_map(fn (string $s): array => ['target' => $s, 'published' => isset($published[explode('::', $s, 2)[0]])], $sees),
            'inherited' => [],
            'implementers' => [],
        ];
    }

    /**
     * Second pass: inherited members (through published parents and traits; the first parent outside the published
     * set ends the walk, and that parent is plain text) and implementers (published classes and enums whose own or
     * published ancestors' interfaces name the interface).
     *
     * @param  array<string, array<string, mixed>>  $symbols
     * @param  array<string, array<string, mixed>>  $published
     */
    private function crossLink(array &$symbols, array $published): void
    {
        foreach ($symbols as $fqn => &$symbol) {
            $own = [];

            foreach (['methods', 'properties', 'constants'] as $kind) {
                foreach ($symbol[$kind] as $member) {
                    $own[$kind][$member['name']] = true;
                }
            }

            $inherited = [];
            $sources = [...array_map(fn (array $l): string => $l['fqn'], $symbol['traits'])];
            $parent = $symbol['kind'] === 'class' ? ($symbol['extends'][0]['fqn'] ?? null) : null;
            $seen = [$fqn => true];

            while ($parent !== null && isset($symbols[$parent]) && ! isset($seen[$parent])) {
                $seen[$parent] = true;
                $sources[] = $parent;
                array_push($sources, ...array_map(fn (array $l): string => $l['fqn'], $symbols[$parent]['traits']));
                $parent = $symbols[$parent]['extends'][0]['fqn'] ?? null;
            }

            if ($symbol['kind'] === 'interface') {
                $queue = array_map(fn (array $l): string => $l['fqn'], $symbol['extends']);

                while ($queue !== []) {
                    $next = array_shift($queue);

                    if (isset($symbols[$next]) && ! isset($seen[$next])) {
                        $seen[$next] = true;
                        $sources[] = $next;
                        array_push($queue, ...array_map(fn (array $l): string => $l['fqn'], $symbols[$next]['extends']));
                    }
                }
            }

            foreach ($sources as $from) {
                if (! isset($symbols[$from])) {
                    continue;
                }

                foreach (['methods' => 'method', 'properties' => 'property', 'constants' => 'constant'] as $kind => $label) {
                    foreach ($symbols[$from][$kind] as $member) {
                        if (isset($own[$kind][$member['name']])) {
                            continue;
                        }

                        $own[$kind][$member['name']] = true;
                        $inherited[] = ['kind' => $label, 'name' => $member['name'], 'from' => $from];
                    }
                }
            }

            usort($inherited, fn (array $a, array $b): int => [$a['kind'], $a['name']] <=> [$b['kind'], $b['name']]);
            $symbol['inherited'] = $inherited;
        }
        unset($symbol);

        // Implementers: for each published class/enum, every interface it (or a published ancestor) implements,
        // closed over published interface parents.
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
