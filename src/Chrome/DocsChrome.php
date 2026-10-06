<?php

namespace Splicewire\Beam\Docs\Chrome;

use Illuminate\Contracts\Auth\Authenticatable;
use Splicewire\Beam\Brand\Brand;
use Splicewire\Beam\Brand\BrandData;
use Splicewire\Beam\Docs\Data\DocsChromeData;
use Splicewire\Beam\Docs\Data\DocsRelatedData;
use Splicewire\Beam\Docs\Data\DocsSurfaceData;
use Splicewire\Beam\Ux\Containment\UrlResolver;
use Splicewire\Beam\Ux\Http\PublicEntryGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

/**
 * docs-walkthrough DM4 (DOCS-12): the one server read behind every docs page's header.
 *
 * - The docs root is the nearest ancestor (or the page itself) that declares the `DocsLayout` layout.
 * - The product is the one this host's docs sources declare (`beam.docs.sources[].product`, DOC-1); its brand is
 *   `Brand::forProduct()` (`beam.brands[product]`, else `beam.brand`; lead ruling 1). An entry-level `brand` key that
 *   inherits like `layout` (C-1) can follow; this host-level product needs no migration.
 * - "Back" is the product brand's `home`, else `/` (lead ruling 2).
 * - The surfaces are the root's `SpreadTemplate` children the reader may open, labelled by their row titles (UX IA-11).
 * - Related is every other product in `beam.brands`, with its tagline (C-6).
 */
final class DocsChrome
{
    public const LAYOUT = 'DocsLayout';

    public const SURFACE_TEMPLATE = 'SpreadTemplate';

    public function __construct(private PublicEntryGate $gate, private UrlResolver $urls) {}

    public function for(BeamUxEntry $entry, ?Authenticatable $actor): DocsChromeData
    {
        $root = $this->rootOf($entry);
        $product = $this->product();
        $brand = Brand::forProduct($product);

        return new DocsChromeData(
            brand: $brand,
            home: $this->urls->resolve($root),
            back: $brand->home ?? '/',
            surfaces: $this->surfaces($root, $actor),
            related: $this->related($product),
        );
    }

    private function rootOf(BeamUxEntry $entry): BeamUxEntry
    {
        for ($node = $entry; $node !== null; $node = $node->parent) {
            if ($node->layout === self::LAYOUT) {
                return $node;
            }
        }

        return $entry;
    }

    /** The product this host's docs sources declare (DOC-1): the first one that names any. */
    private function product(): ?string
    {
        foreach ((array) config('beam.docs.sources', []) as $source) {
            if (is_string($source['product'] ?? null) && $source['product'] !== '') {
                return $source['product'];
            }
        }

        return null;
    }

    /** @return list<DocsSurfaceData> */
    private function surfaces(BeamUxEntry $root, ?Authenticatable $actor): array
    {
        return $root->children()
            ->where('template', self::SURFACE_TEMPLATE)
            ->orderBy('nav_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (BeamUxEntry $child) => $this->gate->chainForEntry($child, (string) ($root->realm ?? BeamUxEntry::REALM_SITE), $actor) !== null)
            ->map(fn (BeamUxEntry $child) => new DocsSurfaceData(label: (string) $child->title, href: $this->urls->resolve($child)))
            ->values()
            ->all();
    }

    /** @return list<DocsRelatedData> */
    private function related(?string $product): array
    {
        $related = [];

        foreach ((array) config('beam.brands', []) as $key => $declared) {
            if ($key === $product || ! is_array($declared)) {
                continue;
            }
            $brand = BrandData::fromConfig($declared);
            $related[] = new DocsRelatedData(
                key: (string) $key,
                name: $brand->name,
                tagline: $brand->tagline,
                href: $brand->home ?? '/',
                docs: is_string($declared['docs'] ?? null) ? $declared['docs'] : null,
            );
        }

        return $related;
    }
}
