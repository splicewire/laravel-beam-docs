<?php

namespace Splicewire\Beam\Docs\Reconcile;

use Illuminate\Support\Facades\Process;

/**
 * What git says happened to a docs source file that is gone (docs-feedback ticket 15): renamed (to where), deleted, or
 * nothing recoverable.
 *
 * A row records no seed commit (its provenance is `origin` + `asserted_hash` only), so there is no "seeded at" range to
 * diff. The path's own history answers instead: first the work tree against HEAD (an uncommitted `git mv` or `git rm`),
 * then the last commit that touched the path, read with rename detection over that whole commit. A rename whose target is
 * itself gone is followed, a few hops at most.
 *
 * Read-only: it runs `git diff`, `git log` and `git show`, never anything that writes.
 */
class SourceHistory
{
    public const RENAMED = 'renamed';

    public const DELETED = 'deleted';

    private const HOPS = 5;

    /**
     * @return ?array{fate: string, to?: string, similarity?: int, commit?: ?string}
     *                                                                               null when the path is in no git work tree or git has no record of it.
     *                                                                               `to` is absolute; `commit` is null for an uncommitted change.
     */
    public function fate(string $absolute): ?array
    {
        $top = $this->toplevel(dirname($absolute));
        if ($top === null) {
            return null;
        }

        $answer = null;
        $path = $absolute;
        for ($hop = 0; $hop < self::HOPS; $hop++) {
            $relative = $this->relative($top, $path);
            if ($relative === null) {
                break;
            }
            $step = $this->step($top, $relative);
            if ($step === null) {
                break;
            }
            $answer = $step;
            if ($step['fate'] !== self::RENAMED || is_file($step['to'])) {
                break;
            }
            $path = $step['to'];
        }

        return $answer;
    }

    /** @return ?array{fate: string, to?: string, similarity?: int, commit?: ?string} */
    private function step(string $top, string $relative): ?array
    {
        // The work tree first: a rename or delete not yet committed.
        $diff = Process::path($top)->run(['git', 'diff', '-M', '--name-status', '-z', 'HEAD', '--']);
        if ($diff->successful() && ($found = $this->find($diff->output(), $relative, $top, null)) !== null) {
            return $found;
        }

        $log = Process::path($top)->run(['git', 'log', '-1', '--format=%H', '--', $relative]);
        $commit = trim($log->output());
        if (! $log->successful() || $commit === '') {
            return null;
        }

        $show = Process::path($top)->run(['git', 'show', '-M', '--name-status', '-z', '--format=', '--diff-merges=first-parent', $commit]);

        return $show->successful() ? $this->find($show->output(), $relative, $top, $commit) : null;
    }

    /** Parse `--name-status -z` output for an entry whose source is `$relative`. */
    private function find(string $output, string $relative, string $top, ?string $commit): ?array
    {
        $fields = explode("\0", ltrim($output, "\n"));
        for ($i = 0; $i < count($fields); $i++) {
            $status = trim($fields[$i]);
            if ($status === '') {
                continue;
            }
            if ($status[0] === 'R' || $status[0] === 'C') {
                [$from, $to] = [$fields[$i + 1] ?? '', $fields[$i + 2] ?? ''];
                $i += 2;
                if ($status[0] === 'R' && $from === $relative) {
                    return ['fate' => self::RENAMED, 'to' => $top.'/'.$to, 'similarity' => (int) substr($status, 1), 'commit' => $commit];
                }

                continue;
            }
            $path = $fields[$i + 1] ?? '';
            $i++;
            if ($status[0] === 'D' && $path === $relative) {
                return ['fate' => self::DELETED, 'commit' => $commit];
            }
        }

        return null;
    }

    private function toplevel(string $directory): ?string
    {
        $real = $this->existingAncestor($directory);
        if ($real === null) {
            return null;
        }
        $result = Process::path($real)->run(['git', 'rev-parse', '--show-toplevel']);
        $top = trim($result->output());

        return $result->successful() && $top !== '' ? (realpath($top) ?: $top) : null;
    }

    /** The nearest directory that still exists (a moved source's own directory may be gone), resolved. */
    private function existingAncestor(string $directory): ?string
    {
        for ($dir = $directory; $dir !== '' && $dir !== '/' && $dir !== '.'; $dir = dirname($dir)) {
            if (is_dir($dir)) {
                return realpath($dir) ?: $dir;
            }
        }

        return null;
    }

    /** The path relative to the work tree, resolving symlinks in its nearest existing ancestor (vendor path repos). */
    private function relative(string $top, string $absolute): ?string
    {
        $tail = [];
        $dir = $absolute;
        while (! is_dir($dir = dirname($dir))) {
            if ($dir === '/' || $dir === '.') {
                return null;
            }
            array_unshift($tail, basename($dir));
        }
        $real = (realpath($dir) ?: $dir).'/'.implode('/', [...$tail, basename($absolute)]);
        $real = str_replace('//', '/', $real);

        return str_starts_with($real, $top.'/') ? substr($real, strlen($top) + 1) : null;
    }
}
