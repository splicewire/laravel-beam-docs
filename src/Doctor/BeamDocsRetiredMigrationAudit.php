<?php

namespace Splicewire\Beam\Docs\Doctor;

use Splicewire\Beam\Doctor\RetiredMigrationAudit;

/**
 * beam-docs' own retirement declarations, on the instrument beam already uses for its own (see
 * {@see RetiredMigrationAudit}: a retirement must be DECLARED, because a retired stub's defining property is that it
 * is absent).
 *
 * migration-flatten 07 retired `shared/adopt_docs_entry_requirement` (02 rulings: delete-data). On a fresh install the
 * docs root does not exist when migrations run, so the stub did nothing; `DocsSeeder::seedRoot()` creates the root with
 * `requirements: ['beam-docs']` and `DocsSeeder::adopt()` adds it to an existing root. Hosts that ran the stub are
 * re-baselined (migration-flatten 04). Advisory, like its parent: a host still holding the published copy is told,
 * and deleting the file stays the host's call.
 */
class BeamDocsRetiredMigrationAudit extends RetiredMigrationAudit
{
    /**
     * The published path with its timestamp stripped => what supersedes it.
     *
     * @var array<string, string>
     */
    public const DOCS_RETIRED = [
        'shared/adopt_docs_entry_requirement' => 'DocsSeeder::adopt() on seed (no migration)',
    ];

    public function __construct(?string $migrationsPath = null)
    {
        parent::__construct(self::DOCS_RETIRED, $migrationsPath);
    }
}
