<?php

namespace Splicewire\Beam\Docs\PhpApi;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A package's PHP source files, from its `composer.json` `autoload`, enumerated the way Composer's own autoload
 * generator does (php-api-reference slice 1, amended for review REVISE fe468ff28 Spec 5):
 *
 * - every `psr-4` root, `.php` files;
 * - every `classmap` entry, a file or a directory, where an entry containing `*` is a glob (Composer's
 *   ClassMapGenerator expands it), and directory scans take Composer's classmap extensions `php`, `inc` and `hh`;
 * - `exclude-from-classmap` patterns applied to the result, translated as Composer translates them: package-relative,
 *   `**` spans directories, `*` stays within one, matched as a prefix of the file's relative path.
 *
 * Only `autoload` is read; `autoload-dev` (tests) is not the package's API. Paths are package-relative and sorted, so
 * the file list (and everything built from it) is deterministic. Nothing is loaded or executed.
 */
final class SourceRoots
{
    /** Composer's classmap extensions (AutoloadGenerator passes these to ClassMapGenerator). */
    public const CLASSMAP_EXTENSIONS = ['php', 'inc', 'hh'];

    /**
     * One file's contribution to a package's `sourceDigest`. ONE definition for the generator that writes the digest
     * and the `docs.php-api` audit that recomputes it (php-api-reference slice 2), so the two cannot drift.
     */
    public static function digestLine(string $file, string $code): string
    {
        return $file."\0".hash('sha256', $code)."\n";
    }

    /** The package's `sourceDigest` over {@see files()}, read as bytes: nothing is parsed, loaded or executed. */
    public function digest(string $packagePath): string
    {
        $digest = hash_init('sha256');
        foreach ($this->files($packagePath)['files'] as $file) {
            hash_update($digest, self::digestLine($file, (string) @file_get_contents($packagePath.'/'.$file)));
        }

        return hash_final($digest);
    }

    /** @return array{files: list<string>, problems: list<string>} */
    public function files(string $packagePath): array
    {
        $problems = [];
        $raw = @file_get_contents($packagePath.'/composer.json');
        $composer = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($composer)) {
            return ['files' => [], 'problems' => ['composer.json could not be read']];
        }

        $autoload = is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $files = [];

        foreach ((array) ($autoload['psr-4'] ?? []) as $dirs) {
            foreach ((array) $dirs as $dir) {
                $this->collect($packagePath, (string) $dir, ['php'], $files, $problems);
            }
        }

        foreach ((array) ($autoload['classmap'] ?? []) as $entry) {
            $entry = (string) $entry;

            if (str_contains($entry, '*')) {
                $matches = glob($packagePath.'/'.ltrim(str_replace('\\', '/', $entry), '/'), GLOB_NOSORT) ?: [];
                sort($matches, SORT_STRING);

                if ($matches === []) {
                    $problems[] = "classmap pattern '{$entry}' matches nothing";
                }

                foreach ($matches as $match) {
                    $this->collect($packagePath, ltrim(substr($match, strlen($packagePath)), '/'), self::CLASSMAP_EXTENSIONS, $files, $problems);
                }

                continue;
            }

            $this->collect($packagePath, $entry, self::CLASSMAP_EXTENSIONS, $files, $problems);
        }

        $excluded = $this->exclusion((array) ($autoload['exclude-from-classmap'] ?? []));
        $list = array_values(array_filter(array_keys($files), fn (string $f): bool => $excluded === null || preg_match($excluded, $f) !== 1));
        sort($list, SORT_STRING);
        $problems = array_values(array_unique($problems));
        sort($problems, SORT_STRING);

        return ['files' => $list, 'problems' => $problems];
    }

    /**
     * @param  list<string>  $extensions
     * @param  array<string, true>  $files
     * @param  list<string>  $problems
     */
    private function collect(string $packagePath, string $root, array $extensions, array &$files, array &$problems): void
    {
        $relative = trim(str_replace('\\', '/', $root), '/');
        $absolute = $packagePath.($relative === '' ? '' : '/'.$relative);

        if (is_file($absolute)) {
            $files[$relative] = true;
        } elseif (is_dir($absolute)) {
            foreach ($this->filesUnder($absolute, $extensions) as $file) {
                $files[ltrim(substr($file, strlen($packagePath)), '/')] = true;
            }
        } else {
            $problems[] = "autoload root '{$root}' does not exist";
        }
    }

    /**
     * Composer's exclude-from-classmap translation, package-relative: `**` any depth, `*` one segment, prefix match.
     *
     * @param  list<mixed>  $patterns
     */
    private function exclusion(array $patterns): ?string
    {
        $parts = [];

        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || trim($pattern) === '') {
                continue;
            }

            $quoted = preg_quote(ltrim(str_replace('\\', '/', $pattern), '/'), '{');
            $parts[] = strtr($quoted, ['\*\*' => '.+?', '\*' => '[^/]*?']);
        }

        return $parts === [] ? null : '{^(?:'.implode('|', $parts).')}';
    }

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    private function filesUnder(string $dir, array $extensions): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $extensions, true)) {
                $found[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        return $found;
    }
}
