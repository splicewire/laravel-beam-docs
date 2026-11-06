<?php

namespace Splicewire\Beam\Docs\Reconcile;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** One planned action for a vestigial row ({@see DocsReconciler}). */
final class ReconcileItem
{
    /** @param  list<string>  $candidates  the files this decision names (the rename target, or the ambiguous candidates) */
    public function __construct(
        public readonly VestigialRow $row,
        public readonly string $action,
        public readonly ?BeamUxEntry $target,
        public readonly string $evidence,
        public readonly array $candidates = [],
    ) {}

    /** The plan line: `name (origin, published) → action[ → target]: evidence`. */
    public function describe(): string
    {
        $target = $this->target !== null ? ' → '.(($this->target->namespace ? $this->target->namespace.'.' : '').$this->target->slug) : '';
        $named = $this->action === DocsReconciler::REPORT && $this->candidates !== [] ? ' (candidate: '.implode(', ', $this->candidates).')' : '';

        return "{$this->row->describe()}: {$this->action}{$target}. {$this->evidence}{$named}";
    }
}
