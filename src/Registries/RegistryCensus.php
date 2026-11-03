<?php

namespace Splicewire\Beam\Docs\Registries;

use ReflectionClass;
use Rushing\Popcorn\Laravel\Baking\DeclaredRegistryScan;

/**
 * The `#[IsRegistry]` declaration census the catalog validates against (registry-docs ticket 01,
 * amended ruling 3): the static source scan, never any one host's bake or runtime index — one
 * host's bake lacks roots the product documents (www is missing 39 of the flagship's), and dead
 * roots can only be detected against what is DECLARED. Delegates to popcorn's
 * {@see DeclaredRegistryScan}, which already skips the empty root structurally (the index describes
 * itself; `''` is not a legal key) and honours `popcorn.bake.paths` — the seam a spine host or a
 * test harness uses to widen the scan beyond this process's own install.
 */
class RegistryCensus
{
    public function __construct(private DeclaredRegistryScan $scan) {}

    /** @return array<string, array{class: class-string, file: string}> root => its declaring class and file, root-sorted */
    public function entries(): array
    {
        $entries = [];

        foreach ($this->scan->run()['map'] as $root => $row) {
            $entries[$root] = [
                'class' => $row['class'],
                'file' => (string) (new ReflectionClass($row['class']))->getFileName(),
            ];
        }

        ksort($entries);

        return $entries;
    }
}
