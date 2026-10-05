<?php

namespace Splicewire\Beam\Docs\Seed;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
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
        $root ??= $this->seedPage((string) config('beam.docs.root_slug', 'docs'), $index->body, array_merge($index->columns(), [
            'segment' => config('beam.docs.segment', config('beam.ux.docs.segment', '/docs')),
            'parent_id' => BeamUxEntry::rootFor(BeamUxEntry::REALM_SITE)->getKey(),
            'requirements' => ['beam-docs'],
        ]), namespace: config('beam.docs.root_namespace'));
        if ($root === null) {
            return;
        }
        $api = $this->stub('api.mdx');
        $this->seedPage('docs-api', $api->body, array_merge($api->columns(), [
            'parent_id' => $root->getKey(),
        ]));
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

        return StubContent::parse(str_replace('{{ openapi_url }}', route('beam.openapi.yaml', absolute: false), (string) file_get_contents($path)));
    }
}
