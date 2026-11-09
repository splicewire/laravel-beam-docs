<?php

namespace Fixture\ApiE;

/**
 * A published trait whose private method is aliased public elsewhere (cross-file).
 *
 * @api
 */
trait Citing
{
    /**
     * Cites a source.
     *
     * Ruling T23 explains why the citation stays.
     *
     * @see Parent_
     */
    private function privateCite(): void {}

    /**
     * Never aliased.
     *
     * @see Needs
     */
    private function neverAliased(): void {}

    /**
     * @internal
     *
     * @see ChildOfHidden
     */
    public function internalCite(): void {}
}
