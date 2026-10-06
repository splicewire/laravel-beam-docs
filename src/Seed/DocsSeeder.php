<?php

namespace Splicewire\Beam\Docs\Seed;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Splicewire\Beam\Docs\OpenApi\ReferenceSubject;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Seed\SeedsEntries;
use Splicewire\Beam\Ux\Seed\StubContent;

class DocsSeeder extends Seeder
{
    use SeedsEntries;

    /**
     * The docs root and its packaged pages, then every declared docs source ({@see DocsSourcesSeeder}). One seed step
     * per package: {@see \Splicewire\Beam\Seed\BeamSeedManifest} keys steps by package name, so the sources run
     * from here rather than as a second registration, which would replace this one (docs-walkthrough DOCS-05).
     */
    public function run(): void
    {
        if (! $this->canSeed()) {
            return;
        }
        $this->seedRoot();
        (new DocsSourcesSeeder)->run();
    }

    private function seedRoot(): void
    {
        if (! Schema::hasColumn('beam_ux_entries', 'requirements')) {
            throw new RuntimeException('Run the Beam UX requirements migration before adopting documentation.');
        }
        $root = $this->existingRoot();
        if ($root !== null) {
            $this->adopt($root);
        }
        if (! config('beam.docs.enabled', true) || ! config('beam.docs.seed', true)) {
            return;
        }
        $index = $this->stub('index.mdx');
        // An existing root goes through seedPage() too: it is not re-created or moved, but a pristine package: root
        // re-asserts to the current stub (DOCS-06b). Only creating it is conditional, as before.
        $seeded = $this->seedPage((string) config('beam.docs.root_slug', 'docs'), $index->body, array_merge($index->columns(), [
            'segment' => config('beam.docs.segment', config('beam.ux.docs.segment', '/docs')),
            'parent_id' => BeamUxEntry::rootFor(BeamUxEntry::REALM_SITE)->getKey(),
            'requirements' => ['beam-docs'],
        ]), namespace: config('beam.docs.root_namespace'), origin: \Splicewire\Beam\Ux\Provenance\Provenance::package('splicewire/laravel-beam-docs'));
        $root ??= $seeded;
        if ($root === null) {
            return;
        }
        $api = $this->stub('api.mdx');
        // docs-walkthrough DOC-12: a root documenting a PRODUCT with no product artifact publishes no reference surface,
        // or this host's own routes would render as that product's API. The row is seeded unpublished (`access: []`),
        // never skipped or deleted, so it is there for the day `beam.docs.openapi.artifact` names the product's spec. An
        // existing row keeps its site-owned `access` (the Reasserter never re-asserts it).
        $subject = ReferenceSubject::fromConfig();
        $this->seedPage('docs-api', $api->body, array_merge($api->columns(), [
            'parent_id' => $root->getKey(),
        ], $subject->isProduct() && ! $subject->hasArtifact() ? ['access' => []] : []), origin: \Splicewire\Beam\Ux\Provenance\Provenance::package('splicewire/laravel-beam-docs'));
    }

    public function existingRoot(): ?BeamUxEntry
    {
        return BeamUxEntry::query()->where('slug', config('beam.docs.root_slug', 'docs'))
            ->where('namespace', config('beam.docs.root_namespace'))->first();
    }

    public function adopt(BeamUxEntry $root): void
    {
        $requirements = $root->requirements ?? [];
        if (! in_array('beam-docs', $requirements, true)) {
            $root->requirements = [...$requirements, 'beam-docs'];
            $root->save();
        }
    }

    private function stub(string $name): StubContent
    {
        // Preserve previously published host stubs while moving package ownership.
        $published = resource_path('beam-ux/docs/'.$name);
        $path = is_file($published) ? $published : __DIR__.'/../../stubs/docs/'.$name;

        // The host's brand titles the landing (docs-walkthrough DOC-10, DOCS-11), never "Documentation" with a note about who
        // seeded it. It is made safe for each place it lands (review-r1, build.qa): beam-mdx's FrontmatterParser reads a line
        // as `key: value` up to the end of the line (no YAML quoting or escapes), so `{{ brand_title }}` is the brand on ONE
        // line; `{{ brand }}` in the body has MDX's expression and JSX characters as entities. A brand with `{`, `<`, `:`,
        // `#` or quotes then neither breaks the compile nor the frontmatter.
        $brand = (string) (config('beam.brand.name') ?: config('app.name'));

        return StubContent::parse(strtr((string) file_get_contents($path), [
            '{{ openapi_url }}' => route('beam.openapi.yaml', absolute: false),
            '{{ brand_title }}' => preg_replace('/\s+/', ' ', $brand).' documentation',
            '{{ brand }}' => strtr($brand, ['{' => '&#123;', '}' => '&#125;', '<' => '&lt;', '>' => '&gt;']),
        ]));
    }
}
