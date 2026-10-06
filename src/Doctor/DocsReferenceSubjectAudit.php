<?php

namespace Splicewire\Beam\Docs\Doctor;

use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Docs\OpenApi\ReferenceSubject;
use Splicewire\Beam\OpenApi\ConfiguredArtifactSpecSource;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * `docs.reference-subject` (docs-walkthrough DOC-12, DM7, DOCS-10): what an API reference documents is declared, and a
 * public one documents only that.
 *
 * Three findings:
 * - **api-root**: the served spec carries no operation under `beam.ux.api_root`, the CMS's own authoring API. Measured
 *   before the fix: `/beam/ux/artifacts/{entry}/{version}` at beam.test, and at splicewire.test the CMS ops too.
 * - **title**: its `info.title` is never the framework default, "Laravel API" (Scribe's `app.name.' API'` on a host with
 *   no brand). Both live specs read exactly that.
 * - **surface**: a root whose `beam.docs.openapi.subject` is a PRODUCT with no product artifact publishes no reference
 *   surface. Its `docs-api` row is unpublished by its `access` (`[]`), never deleted, so the row survives for the day the
 *   artifact exists. Otherwise the host's own routes render as the product's API.
 *
 * The spec checks read the SERVED artifact, shallowly, as `ScribeOutputContractAudit` does; the surface check reads the
 * row, so it reaches rows seeded before the fix. A missing artifact or table is inconclusive, not a pass.
 */
class DocsReferenceSubjectAudit implements DoctorAudit
{
    public const API_ROOT = 'docs.reference-subject: no api_root operation in the public spec';

    public const TITLE = 'docs.reference-subject: the spec title is not the framework default';

    public const SURFACE = 'docs.reference-subject: a product root with no artifact publishes no reference';

    public const FRAMEWORK_DEFAULT_TITLE = 'Laravel API';

    public function __construct(private ConfiguredArtifactSpecSource $source) {}

    /** @return list<Finding> */
    public function run(): array
    {
        $yaml = $this->artifact();

        return [$this->apiRoot($yaml), $this->title($yaml), $this->surface()];
    }

    private function artifact(): ?string
    {
        $path = $this->source->artifactPath();
        $contents = is_file($path) ? @file_get_contents($path) : false;

        return is_string($contents) && trim($contents) !== '' ? $contents : null;
    }

    private function apiRoot(?string $yaml): Finding
    {
        if ($yaml === null) {
            return Finding::inconclusive(self::API_ROOT, 'No generated spec to read; regenerate it (scribe:generate) and re-run.');
        }

        $root = '/'.(trim((string) config('beam.ux.api_root'), '/') ?: 'beam/ux').'/';
        $offending = array_values(array_filter($this->paths($yaml), fn (string $path): bool => str_starts_with($path.'/', $root)));

        return $offending === []
            ? Finding::pass(self::API_ROOT, "The public spec carries no operation under {$root}.")
            : Finding::fail(self::API_ROOT, sprintf(
                '%d operation path(s) under %s are in the public spec: %s. Exclude `%s*` in the Scribe config (the published beam stub does) and regenerate.',
                count($offending), $root, implode(', ', $offending), ltrim($root, '/'),
            ));
    }

    private function title(?string $yaml): Finding
    {
        if ($yaml === null) {
            return Finding::inconclusive(self::TITLE, 'No generated spec to read; regenerate it (scribe:generate) and re-run.');
        }

        $title = $this->infoTitle($yaml);

        if ($title === null) {
            return Finding::inconclusive(self::TITLE, 'The spec declares no info.title.');
        }

        return $title === self::FRAMEWORK_DEFAULT_TITLE
            ? Finding::fail(self::TITLE, sprintf(
                'The spec is titled "%s", the framework default. Declare the host brand (`config/beam/brand.php` `name`); the beam Scribe stub titles the spec from it.',
                $title,
            ))
            : Finding::pass(self::TITLE, "The spec is titled \"{$title}\".");
    }

    private function surface(): Finding
    {
        $subject = ReferenceSubject::fromConfig();

        if (! $subject->isProduct()) {
            return Finding::pass(self::SURFACE, 'The reference documents this host (subject: self).');
        }

        if ($subject->hasArtifact()) {
            return Finding::pass(self::SURFACE, "The reference documents {$subject->product}'s declared artifact.");
        }

        if (! Schema::hasTable('beam_ux_entries')) {
            return Finding::inconclusive(self::SURFACE, 'The entries table is not migrated here.');
        }

        $row = BeamUxEntry::query()->where('slug', 'docs-api')->whereNull('namespace')->first();

        if ($row === null || $row->access === []) {
            return Finding::pass(self::SURFACE, "subject {$subject} has no artifact, and no reference surface is published.");
        }

        return Finding::fail(self::SURFACE, sprintf(
            'subject %s has no product artifact, but the docs-api row is published, so this host\'s own routes render as that product\'s API. Unpublish it by `access` (`[]`), do not delete it, until `beam.docs.openapi.artifact` names the product\'s spec.',
            $subject,
        ));
    }

    /** @return list<string> the top-level keys under `paths:` */
    private function paths(string $yaml): array
    {
        $paths = [];
        $in = false;
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('/^paths:\s*$/', $line) === 1) {
                $in = true;

                continue;
            }
            if ($in && preg_match('/^\S/', $line) === 1) {
                break;
            }
            if ($in && preg_match('/^  (?:[\'"])?(\/[^\'":]*)(?:[\'"])?:\s*$/', $line, $m) === 1) {
                $paths[] = $m[1];
            }
        }

        return $paths;
    }

    private function infoTitle(string $yaml): ?string
    {
        $in = false;
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('/^info:\s*$/', $line) === 1) {
                $in = true;

                continue;
            }
            if ($in && preg_match('/^\S/', $line) === 1) {
                break;
            }
            if ($in && preg_match('/^  title:\s*(.+?)\s*$/', $line, $m) === 1) {
                return trim($m[1], " '\"");
            }
        }

        return null;
    }
}
