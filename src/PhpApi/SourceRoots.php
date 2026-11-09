<?php

namespace Splicewire\Beam\Docs\PhpApi;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A package's PHP source files, from its `composer.json` `autoload`: every `psr-4` root and every `classmap` entry
 * (so a `legacy/` classmap is covered). Only `autoload` is read; `autoload-dev` (tests) is not the package's API.
 * Paths are package-relative and sorted, so the file list (and everything built from it) is deterministic.
 */
final class SourceRoots
{
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
        $roots = [];

        foreach ((array) ($autoload['psr-4'] ?? []) as $dirs) {
            foreach ((array) $dirs as $dir) {
                $roots[] = (string) $dir;
            }
        }

        foreach ((array) ($autoload['classmap'] ?? []) as $entry) {
            $roots[] = (string) $entry;
        }

        $files = [];

        foreach (array_unique($roots) as $root) {
            $relative = trim(str_replace('\\', '/', $root), '/');
            $absolute = $packagePath.($relative === '' ? '' : '/'.$relative);

            if (is_file($absolute)) {
                $files[$relative] = true;
            } elseif (is_dir($absolute)) {
                foreach ($this->phpFilesUnder($absolute) as $file) {
                    $files[ltrim(substr($file, strlen($packagePath)), '/')] = true;
                }
            } else {
                $problems[] = "autoload root '{$root}' does not exist";
            }
        }

        $list = array_keys($files);
        sort($list, SORT_STRING);
        sort($problems, SORT_STRING);

        return ['files' => $list, 'problems' => $problems];
    }

    /** @return list<string> */
    private function phpFilesUnder(string $dir): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $found[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        return $found;
    }
}
