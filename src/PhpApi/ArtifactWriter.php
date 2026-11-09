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
 * The write builds the whole tree beside the target and swaps it in. An existing target is replaced only when it is a
 * real directory carrying this writer's marker; a symlink target is refused outright, so a misconfigured path can never
 * delete someone else's directory or write through a link. Every rename is checked, and a failed publication restores
 * the previous artifact or leaves it, reported, where it can be recovered ({@see publish()}).
 */
final class ArtifactWriter
{
    public const MARKER = '.beam-docs-php-api';

    /** @param  ?\Closure(string, string): bool  $rename  the rename used to publish; a seam for failure injection in tests */
    public function __construct(private readonly ?\Closure $rename = null) {}

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

        // Amend S1: a symlink is never a target. Replacing it would rename the link and then delete THROUGH it (the
        // filesystem follows a root link), destroying the destination. Refused before anything is created.
        if (is_link($target)) {
            throw new RuntimeException("{$target} is a symlink; refusing to write the PHP API artifact through it (point beam.docs.php_api.artifact at a real directory).");
        }

        if (file_exists($target) && ! is_dir($target)) {
            throw new RuntimeException("{$target} exists and is not a directory; refusing to replace it.");
        }

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
        } catch (\Throwable $e) {
            $fs->deleteDirectory($staging);

            throw $e;
        }

        $this->publish($fs, $staging, $target);
    }

    /**
     * Amend S2: swap the staged tree in, checking every rename. The previous artifact is moved aside first; if
     * publication then fails it is moved back; if THAT fails it is kept where it is and named in the error (never
     * deleted, never reported as success). The displaced copy is deleted only after publication is confirmed, and
     * only when it is a real directory carrying this writer's marker.
     */
    private function publish(Filesystem $fs, string $staging, string $target): void
    {
        $rename = $this->rename ?? static fn (string $from, string $to): bool => @rename($from, $to);
        $old = null;

        if (is_dir($target)) {
            $old = $target.'.replaced-'.bin2hex(random_bytes(6));

            if (! $rename($target, $old)) {
                $fs->deleteDirectory($staging);

                throw new RuntimeException("Could not move the existing artifact at {$target} aside; nothing was replaced.");
            }
        }

        if (! $rename($staging, $target)) {
            $fs->deleteDirectory($staging);

            if ($old === null) {
                throw new RuntimeException("Could not publish the artifact to {$target}; nothing was replaced.");
            }

            if ($rename($old, $target)) {
                throw new RuntimeException("Could not publish the artifact to {$target}; the previous artifact was restored.");
            }

            throw new RuntimeException("Could not publish the artifact to {$target}, and the previous artifact could not be restored: it is preserved at {$old} (not deleted).");
        }

        if ($old !== null && ! is_link($old) && is_file($old.'/'.self::MARKER)) {
            $fs->deleteDirectory($old);
        }
    }
}
