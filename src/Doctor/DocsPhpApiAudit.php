<?php

namespace Splicewire\Beam\Docs\Doctor;

use Rushing\Doctor\DoctorAudit;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Docs\Console\GeneratePhpApiCommand;
use Splicewire\Beam\Docs\PhpApi\ArtifactWriter;
use Splicewire\Beam\Docs\PhpApi\PhpApiArtifact;
use Splicewire\Beam\Docs\PhpApi\PhpApiGenerator;
use Splicewire\Beam\Docs\PhpApi\PhpApiPackages;
use Splicewire\Beam\Docs\PhpApi\SourceRoots;

/**
 * `docs.php-api` (php-api-reference slice 2): whether the generated PHP API artifact matches what is configured and the
 * source it was generated from. This is where source staleness is judged: a request only checks the manifest against
 * the configuration ({@see PhpApiArtifact}); this audit also recomputes every listed
 * package's `sourceDigest` (file bytes over the same autoload roots, {@see SourceRoots::digest()}). Nothing is parsed,
 * loaded or executed, and no withheld text is echoed.
 *
 * - An empty `beam.docs.php_api.packages` is the explicit off state: PASS.
 * - FAIL: a listed package that does not resolve; an artifact that is missing, not the writer's (a symlink, no marker),
 *   unreadable, of another schema or audience, generated from another list or reference patterns, or older than a
 *   listed package's source.
 * - WARN (advisory): paragraphs withheld per package, and a listed package with no `@api` symbol under `public`.
 */
class DocsPhpApiAudit implements DoctorAudit
{
    public const CHECK = 'docs.php-api';

    public function __construct(private PhpApiPackages $packages, private SourceRoots $roots) {}

    public function run(): array
    {
        $configured = (array) config('beam.docs.php_api.packages', []);
        if ($configured === []) {
            return [Finding::pass(self::CHECK, 'beam.docs.php_api.packages is empty: the PHP API reference is off.')];
        }

        $failures = [];
        $advisories = [];
        $resolved = $this->packages->resolve($configured);
        foreach ($resolved as $package) {
            if ($package['path'] === null) {
                $failures[] = "{$package['name']}: unresolved ({$package['source']})";
            }
        }

        $root = GeneratePhpApiCommand::artifactPath();
        [$manifest, $problem] = $this->manifest($root);
        if ($problem !== null) {
            $failures[] = $problem;
        } else {
            $audience = GeneratePhpApiCommand::audience();
            $patterns = array_values((array) config('beam.docs.php_api.references', []));
            if (($manifest['schema'] ?? null) !== PhpApiGenerator::SCHEMA) {
                $failures[] = 'artifact schema is not '.PhpApiGenerator::SCHEMA;
            } elseif (($manifest['audience'] ?? null) !== $audience) {
                $failures[] = 'artifact audience is '.json_encode($manifest['audience'] ?? null).", configured audience is {$audience}";
            } elseif (($manifest['inputDigest'] ?? null) !== ArtifactWriter::inputDigest($configured, $audience, $patterns)) {
                $failures[] = 'artifact is stale: the package list or reference patterns changed since it was generated';
            } else {
                $rows = [];
                foreach ((array) ($manifest['packages'] ?? []) as $row) {
                    if (is_array($row) && is_string($row['name'] ?? null)) {
                        $rows[$row['name']] = $row;
                    }
                }
                foreach ($resolved as $package) {
                    $row = $rows[$package['name']] ?? null;
                    if ($package['path'] === null) {
                        continue;
                    }
                    if ($row === null || ($row['sourceDigest'] ?? null) !== $this->roots->digest($package['path'])) {
                        $failures[] = "{$package['name']}: artifact is stale, its source changed since generation";
                    } elseif ($audience === 'public' && ($row['status'] ?? null) === 'ok' && ($row['published'] ?? 0) === 0) {
                        $advisories[] = "{$package['name']}: no @api symbols under the public audience";
                    }
                }
                foreach ($this->report($root) as $report) {
                    $withheld = is_array($report['withheld'] ?? null) ? count($report['withheld']) : 0;
                    if ($withheld > 0 && is_string($report['name'] ?? null)) {
                        $advisories[] = "{$report['name']}: {$withheld} paragraph(s) withheld (rewrite at source; see report.json)";
                    }
                }
            }
        }

        if ($failures !== []) {
            return [Finding::fail(self::CHECK, implode('; ', $failures).'. Run splicewire:beam:docs:php-api:generate.'
                .($advisories !== [] ? ' Advisory: '.implode('; ', $advisories).'.' : ''))];
        }
        if ($advisories !== []) {
            return [Finding::warn(self::CHECK, 'The PHP API artifact is current. Advisory: '.implode('; ', $advisories).'.')];
        }

        return [Finding::pass(self::CHECK, 'The PHP API artifact is current for '.count($resolved).' listed package(s).')];
    }

    /** @return array{0: ?array<string, mixed>, 1: ?string} the manifest, or the reason there is none */
    private function manifest(string $root): array
    {
        if (is_link($root)) {
            return [null, "the artifact path {$root} is a symlink, not the writer's directory"];
        }
        if (! is_dir($root)) {
            return [null, "the artifact is missing at {$root}"];
        }
        if (! is_file($root.'/'.ArtifactWriter::MARKER)) {
            return [null, "{$root} carries no ".ArtifactWriter::MARKER.' marker: not the writer\'s artifact'];
        }
        $manifest = json_decode((string) @file_get_contents($root.'/manifest.json'), true);

        return is_array($manifest) ? [$manifest, null] : [null, 'the artifact manifest is missing or unreadable'];
    }

    /** @return list<array<string, mixed>> */
    private function report(string $root): array
    {
        $report = json_decode((string) @file_get_contents($root.'/report.json'), true);

        return is_array($report) && is_array($report['packages'] ?? null) ? array_values(array_filter($report['packages'], 'is_array')) : [];
    }
}
