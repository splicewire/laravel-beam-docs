<?php

namespace Splicewire\Beam\Docs\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Docs\Reconcile\DocsReconciler;
use Splicewire\Beam\Docs\Reconcile\ReconcileItem;
use Splicewire\Beam\Docs\Seed\DocsSourcesSeeder;

/**
 * Take down the pages of moved or deleted docs sources (docs-feedback ticket 15). A dry run by default: it prints one
 * plan line per vestigial row ({@see DocsReconciler}) and writes nothing. `--apply` takes each planned `redirect` and
 * `takedown` down through its own Release as `--actor`, and appends one JSON receipt line per row it acted on.
 *
 * The seed chain prints the same plan after it registers the sources ({@see DocsSourcesSeeder}),
 * so a rename is seen on the seed that registers its new file; applying it stays an operator's explicit act, because a
 * Release needs an author.
 */
class DocsReconcileCommand extends Command
{
    protected $signature = 'splicewire:beam:docs:reconcile
        {--apply : Take the planned rows down through the Release path (default: dry run, nothing written)}
        {--actor= : With --apply: the id or email of the user who authors the take-down Releases}
        {--receipts= : With --apply: the JSON-lines receipt file (default: storage/logs/beam-docs-reconcile.jsonl)}';

    protected $description = 'Plan, then with --apply take down, the pages whose docs source file was moved (with a redirect) or deleted.';

    public function handle(DocsReconciler $reconciler): int
    {
        $plan = $reconciler->plan();
        if ($plan === []) {
            $this->info('No vestigial row: every seeded row has its file under its origin\'s declared docs sources.');

            return self::SUCCESS;
        }

        $this->table(['row', 'origin', 'action', 'target', 'evidence'], array_map(fn (ReconcileItem $item) => [
            $item->row->name(),
            (string) $item->row->entry->origin,
            $item->action,
            $item->target !== null ? (($item->target->namespace ? $item->target->namespace.'.' : '').$item->target->slug) : ($item->action === DocsReconciler::REPORT && $item->candidates !== [] ? 'candidate: '.implode(', ', $item->candidates) : ''),
            $item->evidence,
        ], $plan));
        $count = fn (string $action) => count(array_filter($plan, fn (ReconcileItem $item) => $item->action === $action));
        $this->line(sprintf('%d vestigial · %d redirect · %d takedown · %d report · %d already down.',
            count($plan), $count(DocsReconciler::REDIRECT), $count(DocsReconciler::TAKEDOWN), $count(DocsReconciler::REPORT), $count(DocsReconciler::DOWN)));

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Re-run with --apply --actor=<user id or email> to take the redirect and takedown rows down.');

            return self::SUCCESS;
        }

        $actor = $this->actor((string) $this->option('actor'));
        if ($actor === null) {
            return self::FAILURE;
        }

        $receipts = (string) ($this->option('receipts') ?: storage_path('logs/beam-docs-reconcile.jsonl'));
        @mkdir(dirname($receipts), 0777, true);
        $failed = 0;
        foreach ($reconciler->apply($plan, $actor) as $outcome) {
            $item = $outcome['item'];
            $entry = $item->row->entry;
            file_put_contents($receipts, json_encode([
                'at' => now()->toIso8601String(),
                'kind' => 'docs-takedown',
                'entry' => (string) $entry->id,
                'namespace' => $entry->namespace,
                'slug' => $entry->slug,
                'origin' => $entry->origin,
                'action' => $item->action,
                'target' => $item->target?->id === null ? null : (string) $item->target->id,
                'evidence' => $item->evidence,
                'release' => $outcome['release'],
                'status' => $outcome['status'],
                'error' => $outcome['error'],
                'actor' => (string) $actor->getAuthIdentifier(),
            ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
            if ($outcome['error'] !== null) {
                $failed++;
                $this->error("{$item->row->name()}: refused: {$outcome['error']}");
            } else {
                $this->line("{$item->row->name()}: release {$outcome['release']} {$outcome['status']}".($item->target !== null ? ' (redirects to '.(($item->target->namespace ? $item->target->namespace.'.' : '').$item->target->slug).')' : ''));
            }
        }
        $this->info("Receipts appended to {$receipts}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function actor(string $reference): ?Authenticatable
    {
        if ($reference === '') {
            $this->error('--apply needs --actor=<user id or email>: a take-down is a Release, and a Release has an author.');

            return null;
        }
        $model = config('auth.providers.users.model');
        $actor = is_string($model) && class_exists($model)
            ? (str_contains($reference, '@') ? $model::query()->where('email', $reference)->first() : $model::query()->whereKey($reference)->first())
            : null;
        if (! $actor instanceof Authenticatable) {
            $this->error("No user [{$reference}] to author the take-down Releases.");

            return null;
        }

        return $actor;
    }
}
