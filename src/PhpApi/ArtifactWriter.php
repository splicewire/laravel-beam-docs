<?php

namespace Splicewire\Beam\Docs\PhpApi;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * The artifact's shape and its write (php-api-reference slice 1).
 *
 *   manifest.json                       schema, audience, digests, one row per configured package, totals
 *   packages/<vendor>/<name>.json       that package's symbol index: fqn, kind, summary, and the symbol file's path
 *   symbols/<sha256(fqn)>.json          one symbol: signatures, members, docs, links
 *   report.json                         per package: files, problems, parse errors, published/hidden counts,
 *                                       citations stripped, and WHERE paragraphs were withheld (never their text)
 *
 * The manifest alone answers slice 2's states without walking the directory: a missing or unreadable manifest is
 * "missing"; `inputDigest` against the current config is "stale" config; a package row's `status` (`ok` or
 * `unresolved`) and `published` count, and `totals.published`, are "no published symbol". The index holds every
 * symbol path, so a reader never forms a path from input. No timestamps or absolute paths are written, and every list
 * is sorted, so the same input writes byte-identical files.
 *
 * The write builds the whole tree beside the target and swaps it in. An existing target is replaced only when it
 * carries this writer's marker, so a misconfigured path can never delete someone else's directory.
 */
final class ArtifactWriter
{
    public const MARKER = '.beam-docs-php-api';

    /**
     * @param  list<array<string, mixed>>  $packages
     * @param  array<string, array<string, mixed>>  $reports
     * @param  array<string, array<string, mixed>>  $symbols
     * @return array{manifest: array<string, mixed>, indexes: array<string, array<string, mixed>>, symbols: array<string, array<string, mixed>>, report: array<string, mixed>}
     */
    public function shape(array $packages, array $reports, array $symbols, string $audience, array $configured, array $patterns): array
    {
        $indexes = [];
        $files = [];

        foreach ($symbols as $fqn => $symbol) {
            $path = self::symbolPath($fqn);
            $files[$path] = $symbol;
            $indexes[$symbol['package']][] = [
                'fqn' => $fqn,
                'kind' => $symbol['kind'],
                'summary' => $symbol['doc'][0] ?? null,
                'path' => $path,
            ];
        }

        $rows = [];
        $published = 0;

        foreach ($packages as $package) {
            $name = $package['name'];
            $report = $reports[$name];
            $count = $report['published'];
            $published += $count;
            $rows[] = [
                'name' => $name,
                'source' => $package['source'],
                'version' => $package['version'],
                'reference' => $package['reference'],
                'status' => $report['status'],
                'published' => $count,
                'index' => $report['status'] === 'ok' ? self::indexPath($name) : null,
                'sourceDigest' => $report['sourceDigest'] ?? null,
            ];
        }

        $shapedIndexes = [];

        foreach ($packages as $package) {
            if ($reports[$package['name']]['status'] !== 'ok') {
                continue;
            }

            $list = $indexes[$package['name']] ?? [];
            usort($list, fn (array $a, array $b): int => strcmp($a['fqn'], $b['fqn']));
            $shapedIndexes[self::indexPath($package['name'])] = ['schema' => PhpApiGenerator::SCHEMA, 'package' => $package['name'], 'symbols' => $list];
        }

        ksort($files, SORT_STRING);
        ksort($shapedIndexes, SORT_STRING);

        $manifest = [
            'schema' => PhpApiGenerator::SCHEMA,
            'audience' => $audience,
            'inputDigest' => self::inputDigest($configured, $audience, $patterns),
            'packages' => $rows,
            'totals' => ['packages' => count($rows), 'published' => $published],
        ];

        $report = ['schema' => PhpApiGenerator::SCHEMA, 'audience' => $audience, 'packages' => array_values(array_map(function (array $r): array {
            usort($r['withheld'], fn (array $a, array $b): int => [$a['symbol'], $a['member'], $a['paragraph']] <=> [$b['symbol'], $b['member'], $b['paragraph']]);
            unset($r['sourceDigest']);

            return $r;
        }, array_values($reports)))];

        return ['manifest' => $manifest, 'indexes' => $shapedIndexes, 'symbols' => $files, 'report' => $report];
    }

    /** The digest of what this artifact was generated from (sorted package list, audience, patterns): staleness. */
    public static function inputDigest(array $configured, string $audience, array $patterns): string
    {
        $rows = [];

        foreach ($configured as $key => $value) {
            $rows[] = is_int($key) ? (string) $value : $key.'=>'.(is_string($value) ? $value : '');
        }

        sort($rows, SORT_STRING);

        return hash('sha256', json_encode(['packages' => $rows, 'audience' => $audience, 'patterns' => array_values($patterns)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function symbolPath(string $fqn): string
    {
        return 'symbols/'.hash('sha256', $fqn).'.json';
    }

    public static function indexPath(string $package): string
    {
        return 'packages/'.preg_replace('/[^a-z0-9_.\-\/]/', '_', strtolower($package)).'.json';
    }

    /** @param  array{manifest: array<string, mixed>, indexes: array<string, array<string, mixed>>, symbols: array<string, array<string, mixed>>, report: array<string, mixed>}  $artifact */
    public function write(array $artifact, string $target): void
    {
        $fs = new Filesystem;
        $target = rtrim($target, '/');

        if (is_dir($target) && ! is_file($target.'/'.self::MARKER) && (new \FilesystemIterator($target))->valid()) {
            throw new RuntimeException("{$target} exists and is not a PHP API artifact (no ".self::MARKER.' marker); refusing to replace it.');
        }

        $fs->ensureDirectoryExists(dirname($target));
        $staging = $target.'.writing-'.bin2hex(random_bytes(6));
        $fs->ensureDirectoryExists($staging);

        try {
            $put = function (string $relative, array $data) use ($fs, $staging): void {
                $fs->ensureDirectoryExists(dirname($staging.'/'.$relative));
                // Real source can hold bytes that are not UTF-8 (a binary string literal's printed value): they become
                // U+FFFD, deterministically, instead of failing the whole write.
                $fs->put($staging.'/'.$relative, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n");
            };

            $fs->put($staging.'/'.self::MARKER, PhpApiGenerator::SCHEMA."\n");
            $put('manifest.json', $artifact['manifest']);
            $put('report.json', $artifact['report']);

            foreach ($artifact['indexes'] as $path => $index) {
                $put($path, $index);
            }

            foreach ($artifact['symbols'] as $path => $symbol) {
                $put($path, $symbol);
            }

            $old = null;

            if (is_dir($target)) {
                $old = $target.'.replaced-'.bin2hex(random_bytes(6));
                rename($target, $old);
            }

            rename($staging, $target);

            if ($old !== null) {
                $fs->deleteDirectory($old);
            }
        } catch (\Throwable $e) {
            $fs->deleteDirectory($staging);

            throw $e;
        }
    }
}
