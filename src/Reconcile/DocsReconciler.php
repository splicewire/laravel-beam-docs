<?php

namespace Splicewire\Beam\Docs\Reconcile;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Throwable;

/**
 * Retire the page of a moved or deleted docs source (docs-feedback ticket 15; owner 2026-11-05: "delete vestigial page
 * records when changing/moving content").
 *
 * {@see VestigialRows} decides WHICH rows are vestigial from provenance alone. This decides WHAT HAPPENED to each one's
 * file, which only history can answer, and plans one action per row:
 *
 * - **redirect**: the file was renamed to a file that has a published row, so the old row is taken down with a redirect
 *   to the new one. Evidence is git rename detection on the path ({@see SourceHistory}), or, for a registry page, the
 *   registry-root mapping: its file is named for its `#[IsRegistry]` root, so a root respelled between snake and kebab
 *   case maps old to new with no git at all.
 * - **takedown**: git records the file as deleted, and nothing maps it elsewhere. Taken down without a redirect.
 * - **report**: no recoverable history, a rename target with no published row, or two pieces of evidence that disagree.
 *   Nothing is done; the candidate is named.
 * - **down**: the row is already unpublished. Nothing to do; it stays for its history.
 *
 * {@see apply()} acts only through the Release take-down path ({@see ReleaseTakeDown}): never a row delete. A take-down is
 * reversible and keeps the row's history; a delete is neither.
 */
class DocsReconciler
{
    public const REDIRECT = 'redirect';

    public const TAKEDOWN = 'takedown';

    public const REPORT = 'report';

    public const DOWN = 'down';

    public function __construct(
        private VestigialRows $rows,
        private SourceHistory $history,
        private ReleaseTakeDown $takeDown,
    ) {}

    /** @return list<ReconcileItem> */
    public function plan(): array
    {
        $sources = $this->rows->sources();
        $items = [];
        foreach ($this->rows->find() as $row) {
            $items[] = $row->published ? $this->classify($row, $sources) : new ReconcileItem($row, self::DOWN, null, 'already unpublished; kept for its history');
        }

        return $items;
    }

    /**
     * Take down every planned redirect and takedown, one Release each. A refused take-down is recorded and the rest go on.
     *
     * @param  list<ReconcileItem>  $plan
     * @return list<array{item: ReconcileItem, release: ?string, status: string, error: ?string}>
     */
    public function apply(array $plan, Authenticatable $actor): array
    {
        $outcomes = [];
        foreach ($plan as $item) {
            if (! in_array($item->action, [self::REDIRECT, self::TAKEDOWN], true)) {
                continue;
            }
            try {
                [$release, $status] = $this->takeDown->takeDown($item->row->entry, $item->target, $this->title($item), $actor);
                $outcomes[] = ['item' => $item, 'release' => $release, 'status' => $status, 'error' => null];
            } catch (Throwable $e) {
                $outcomes[] = ['item' => $item, 'release' => null, 'status' => 'refused', 'error' => trim($e->getMessage()) ?: $e::class];
            }
        }

        return $outcomes;
    }

    /** @param  list<array{root: string, package: ?string}>  $sources */
    private function classify(VestigialRow $row, array $sources): ReconcileItem
    {
        [$gitTarget, $gitWhy, $deleted] = $this->fromGit($row);
        [$matches, $mapWhy] = $this->fromRegistryRoot($row);
        if (count($matches) > 1) {
            return new ReconcileItem($row, self::REPORT, null, 'ambiguous: '.$mapWhy, array_map(fn ($p) => $this->display($p), $matches));
        }
        $mapped = $matches[0] ?? null;

        if ($gitTarget !== null) {
            if ($mapped !== null && $mapped !== $gitTarget) {
                return new ReconcileItem($row, self::REPORT, null, "ambiguous: git: {$gitWhy}; {$mapWhy}", [$this->display($gitTarget), $this->display($mapped)]);
            }

            return $this->toTarget($row, $this->entryAt($gitTarget, $sources), $gitTarget, $gitWhy);
        }
        if ($mapped !== null) {
            return $this->toTarget($row, $this->entryAt($mapped, $sources), $mapped, $mapWhy.($deleted ? ' (git saw a delete)' : ''));
        }
        if ($deleted) {
            return new ReconcileItem($row, self::TAKEDOWN, null, $gitWhy);
        }

        return new ReconcileItem($row, self::REPORT, null, 'ambiguous: '.($gitWhy ?? 'no recoverable history for '.implode(', ', array_map(fn ($p) => $this->display($p), $row->candidates))), []);
    }

    /** @param  BeamUxEntry|string|null  $entry  the target row, or why there is none */
    private function toTarget(VestigialRow $row, BeamUxEntry|string|null $entry, string $path, string $why): ReconcileItem
    {
        if (! $entry instanceof BeamUxEntry) {
            return new ReconcileItem($row, self::REPORT, null, "ambiguous: {$why}, but ".($entry ?? 'it has no row'), [$this->display($path)]);
        }
        if ($entry->getAttribute('workflow_marking') !== null && $entry->getAttribute('workflow_marking') !== BeamUxEntry::MARKING_PUBLISHED) {
            return new ReconcileItem($row, self::REPORT, null, "ambiguous: {$why}, but its row is not published", [$this->display($path)]);
        }
        if ($entry->realm !== $row->entry->realm) {
            return new ReconcileItem($row, self::REPORT, null, "ambiguous: {$why}, but its row is in another realm ({$entry->realm})", [$this->display($path)]);
        }

        return new ReconcileItem($row, self::REDIRECT, $entry, $why, [$this->display($path)]);
    }

    /** @return array{0: ?string, 1: ?string, 2: bool} the rename target, why, and whether git saw a delete */
    private function fromGit(VestigialRow $row): array
    {
        $deleted = null;
        foreach ($row->candidates as $candidate) {
            $fate = $this->history->fate($candidate);
            if ($fate === null) {
                continue;
            }
            $at = ($fate['commit'] ?? null) !== null ? 'in '.substr((string) $fate['commit'], 0, 12) : 'in the work tree';
            if ($fate['fate'] === SourceHistory::RENAMED) {
                return [$fate['to'], sprintf('git: %s renamed to %s %s (%d%% similar)', $this->display($candidate), $this->display($fate['to']), $at, $fate['similarity'] ?? 0), false];
            }
            $deleted ??= sprintf('git: %s deleted %s', $this->display($candidate), $at);
        }

        return [null, $deleted, $deleted !== null];
    }

    /**
     * A registry page's file is `<root>.mdx`: a root respelled between snake and kebab case is the same root renamed.
     *
     * @return array{0: list<string>, 1: ?string} the mapped files (absolute; one is a rename, several are ambiguous), and why
     */
    private function fromRegistryRoot(VestigialRow $row): array
    {
        $registry = array_values(array_filter($row->sources, fn (array $s) => VestigialRows::isRegistrySource($s)));
        if ($registry === []) {
            return [[], null];
        }
        $want = $this->normalize((string) $row->entry->slug);
        $matches = [];
        foreach ($this->rows->keys($registry) as $key => $absolute) {
            [$namespace, $slug] = explode("\0", $key, 2);
            if ($slug !== $row->entry->slug && ($namespace === '' ? null : $namespace) === $row->entry->namespace && $this->normalize($slug) === $want) {
                $matches[] = $absolute;
            }
        }
        if ($matches === []) {
            return [[], null];
        }
        if (count($matches) > 1) {
            // Several respellings of one root: name them all, map none.
            return [$matches, 'registry-root mapping finds several respellings of '.$row->entry->slug];
        }

        return [$matches, sprintf('registry-root mapping: %s is the same root as %s', basename($matches[0]), $row->entry->slug)];
    }

    /** The row at a file under a declared source, or why there is none. */
    private function entryAt(string $absolute, array $sources): BeamUxEntry|string
    {
        $real = realpath($absolute) ?: $absolute;
        foreach ($sources as $source) {
            if (! str_starts_with($real, $source['root'].'/')) {
                continue;
            }
            $key = $this->rows->keyFor($real, $source);
            if ($key === null) {
                return 'it is not a docs body';
            }
            [$namespace, $slug] = explode("\0", $key, 2);
            $entry = BeamUxEntry::query()->where('slug', $slug)->where('namespace', $namespace === '' ? null : $namespace)->first();

            return $entry ?? 'it has no row yet (seed first)';
        }

        return 'it is outside every declared docs source';
    }

    private function normalize(string $slug): string
    {
        return str_replace('_', '-', strtolower($slug));
    }

    private function title(ReconcileItem $item): string
    {
        $name = $item->row->name();

        return mb_substr($item->target !== null
            ? "Take down: {$name} (its source moved; redirect to ".(($item->target->namespace ? $item->target->namespace.'.' : '').$item->target->slug).')'
            : "Take down: {$name} (its source was deleted)", 0, 255);
    }

    /** A path as an operator reads it: relative to the host's base path when under it. */
    public function display(string $path): string
    {
        $base = rtrim(base_path(), '/').'/';
        foreach ([$path, realpath($path) ?: $path] as $candidate) {
            if (str_starts_with($candidate, $base)) {
                return substr($candidate, strlen($base));
            }
        }

        return $path;
    }
}
