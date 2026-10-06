<?php

namespace Splicewire\Beam\Docs\Seed;

use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Provenance\ProvenanceTemplate;

/**
 * The docs pages this package seeds, as templates the provenance backfill may match a pre-migration row against
 * (docs-walkthrough DOCS-06b): the stubs {@see DocsSeeder} seeds now (a host-published copy first, as the seeder reads
 * it), and the ONE prior template measured on live rows: beam-ux's own docs index (laravel-beam-ux@9f5df5a), seeded
 * before the stub moved here. A row matching it is this package's now.
 */
final class DocsProvenanceTemplates
{
    /** @return list<ProvenanceTemplate> */
    public static function all(): array
    {
        $origin = Provenance::package('splicewire/laravel-beam-docs');
        $rootNamespace = config('beam.docs.root_namespace');
        $rootSlug = (string) config('beam.docs.root_slug', 'docs');
        $stubs = dirname(__DIR__, 2).'/stubs/docs';
        $templates = [];

        foreach ([[$rootNamespace, $rootSlug, 'index.mdx'], [null, 'docs-api', 'api.mdx']] as [$namespace, $slug, $name]) {
            foreach (array_unique([resource_path('beam-ux/docs/'.$name), $stubs.'/'.$name]) as $path) {
                if (is_file($path)) {
                    $templates[] = new ProvenanceTemplate($origin, $namespace, $slug, (string) file_get_contents($path), 'beam-docs '.$name);
                }
            }
        }

        foreach (glob($stubs.'/history/index@*.mdx') ?: [] as $path) {
            $templates[] = new ProvenanceTemplate($origin, $rootNamespace, $rootSlug, (string) file_get_contents($path), 'beam-docs prior '.basename($path, '.mdx'));
        }

        return $templates;
    }
}
