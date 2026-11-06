<?php

namespace Splicewire\Beam\Docs\Reconcile;

use Splicewire\Beam\Ux\Models\BeamUxEntry;

/** One row whose origin file is under none of its origin's declared docs sources ({@see VestigialRows}). */
final class VestigialRow
{
    /**
     * @param  list<string>  $candidates  absolute paths the row's file would have had
     * @param  list<array{root: string, declared: string, package: ?string, type: ?string, ignore: list<string>}>  $sources  its origin's declared sources
     */
    public function __construct(
        public readonly BeamUxEntry $entry,
        public readonly bool $published,
        public readonly ?string $marking,
        public readonly array $candidates,
        public readonly array $sources,
    ) {}

    public function name(): string
    {
        return ($this->entry->namespace ? "{$this->entry->namespace}." : '').$this->entry->slug;
    }

    /** `name (origin, published)` or `name (origin, unpublished: draft)`: the audit's and the command's spelling. */
    public function describe(): string
    {
        return "{$this->name()} ({$this->entry->origin}, ".($this->published ? 'published' : "unpublished: {$this->marking}").')';
    }
}
