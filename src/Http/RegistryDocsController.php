<?php

namespace Splicewire\Beam\Docs\Http;

use Rushing\LaravelDataSchemasScribe\Attributes\ResponseFromData;
use Splicewire\Beam\Docs\Data\RegistryDocUnavailableData;
use Splicewire\Beam\Docs\Registries\RegistryDocsAvailability;
use Splicewire\Beam\Docs\Registries\RegistryDocsCatalog;
use Splicewire\Beam\Ux\Docs\DocsLinkResolver;

/**
 * The registry deep link (registry-docs ticket 01, amended ruling 1): a stable, beam-docs-owned
 * address for "the documentation of registry root X", beside the package's other docs routes. The
 * `{root}` is the dotted root VERBATIM — Laravel's default parameter pattern accepts dots, and no
 * slugifier exists anywhere on this path (ruling 3 / R11; a slugifier would silently mint the
 * lookup table the stable-key design abolishes).
 *
 * It resolves exactly what the {@see DocsLinkResolver} seam resolves — the entries table, then the
 * catalog's external pointer — and REDIRECTS there. A root the catalog knows but this host cannot
 * link answers 200 with the availability annotation ("not available on this host" — shown, not
 * hidden); only a root neither the catalog nor the entries table knows is a 404.
 *
 * Deliberately NOT mounted at the public page path (`{segment}/registries/{root}`): package routes
 * register before a host's `Route::beamUxSite()` catch-all, so a package route there would shadow
 * every served registry page (ADR-0209 §2 — the host owns the wire).
 */
class RegistryDocsController
{
    #[ResponseFromData(RegistryDocUnavailableData::class)]
    public function show(string $root): mixed
    {
        abort_unless(config('beam.docs.enabled', true), 404);

        if (($url = app(DocsLinkResolver::class)->urlFor($root)) !== null) {
            return redirect($url);
        }

        $row = app(RegistryDocsCatalog::class)->row($root);
        abort_if($row === null, 404);

        $describedHere = app(RegistryDocsAvailability::class)->knownLocally($root);

        return response()->json((new RegistryDocUnavailableData(
            root: $root,
            available: false,
            describedHere: $describedHere,
            note: $describedHere === false ? 'not available on this host' : null,
            kind: $row->kind,
            reason: $row->reason,
            pointer: $row->pointer,
        ))->toArray());
    }
}
