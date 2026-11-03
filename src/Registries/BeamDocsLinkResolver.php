<?php

namespace Splicewire\Beam\Docs\Registries;

use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Docs\DocsLinkResolver;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Throwable;

/**
 * beam-docs' rebind of beam-ux's {@see DocsLinkResolver} seam (registry-docs ticket 01, amended
 * ruling 1). It reads the COMMITTED spine catalog and the ENTRIES TABLE — there is no docs
 * registry to read instead: ADR-0210 ruled a docs-surface contribution is a seed row, an endpoint
 * and a generic component, with the entries table as the render-time index.
 *
 * Resolution, first answer wins:
 *  1. the entries table serves a page at the conventional public path
 *     (`{segment}/registries/{root}`, dotted root verbatim) → that page's URL;
 *  2. the catalog points the root at an external URL → that URL;
 *  3. anything else — exempt, uncovered, unknown, the structurally-skipped `''`, or any failure
 *     underneath — → `null`, the seam's "render no link". The seam never throws.
 */
class BeamDocsLinkResolver implements DocsLinkResolver
{
    public function __construct(private RegistryDocsCatalog $catalog) {}

    public function urlFor(string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        try {
            if (($url = $this->localPageUrl($key)) !== null) {
                return $url;
            }

            $row = $this->catalog->row($key);

            return $row?->kind === CatalogRow::KIND_EXTERNAL ? $row->url : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function localPageUrl(string $key): ?string
    {
        if (! Schema::hasTable('beam_ux_entries')) {
            return null;
        }

        $path = rtrim((string) config('beam.docs.segment', '/docs'), '/').'/registries/'.$key;
        $chain = app(EntryPathResolver::class)->resolve($path);
        $entry = is_array($chain) && $chain !== [] ? end($chain) : null;

        return $entry instanceof BeamUxEntry ? $entry->url() : null;
    }
}
