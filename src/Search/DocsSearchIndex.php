<?php

namespace Splicewire\Beam\Docs\Search;

use Illuminate\Support\Facades\Cache;
use Rushing\Versioning\Contracts\VersionStore;
use Splicewire\Beam\Models\BeamParticle;
use Splicewire\Beam\Ux\Compile\EntryArtifactStore;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Throwable;

/**
 * The searchable text under one docs root (docs-walkthrough DM6, DOCS-14), UNGATED and cached.
 *
 * It holds every descendant's title, trail, excerpt and PUBLISHED body text, and nothing about who may read it. The cache
 * is keyed by the descendants' artifact versions (which follow the publication pin) and update stamps, so a publish or an
 * edit moves the key. Gating is never cached: {@see DocsSearchOp} runs the per-entry gate on every request, after this
 * read, so a warm cache can never hand one principal another's rows (lead ruling 08:04Z).
 *
 * The body is the PUBLISHED one (lead ruling 3): with a publication pin, the pinned version's snapshot; without one, the
 * working body, which is then exactly what readers are served (the same fallback {@see EntryArtifactStore::version()}
 * applies). A draft recorded after a publish is never indexed.
 */
final class DocsSearchIndex
{
    private const TTL = 3600;

    public function __construct(
        private StorageDriverResolver $drivers,
        private EntryArtifactStore $artifacts,
        private VersionStore $versions,
    ) {}

    /**
     * @return list<array{id: string, title: string, trail: list<string>, excerpt: ?string, headings: string, text: string, depth: int, order: int, template: ?string}>
     */
    public function for(BeamUxEntry $root): array
    {
        $nodes = $this->descendants($root);
        $stamp = implode('|', array_map(
            fn (array $node) => $node['entry']->getKey().'@'.$this->artifacts->version($node['entry']).'@'.$node['entry']->updated_at?->format('U.u'),
            $nodes,
        ));

        return Cache::remember(
            'beam-docs-search:'.$root->getKey().':'.hash('xxh128', $stamp),
            self::TTL,
            fn () => array_map(fn (array $node) => $this->project($node['entry'], $node['trail'], $node['depth']), $nodes),
        );
    }

    /**
     * Every descendant of the root, breadth first, with its title trail below the root. Ungated on purpose.
     *
     * @return list<array{entry: BeamUxEntry, trail: list<string>, depth: int}>
     */
    private function descendants(BeamUxEntry $root): array
    {
        $out = [];
        $queue = [[$root, [], 0]];

        while ($queue !== []) {
            [$node, $trail, $depth] = array_shift($queue);
            $children = $node->children()->orderBy('nav_order')->orderBy('id')->get();
            foreach ($children as $child) {
                $out[] = ['entry' => $child, 'trail' => $trail, 'depth' => $depth + 1];
                $queue[] = [$child, [...$trail, (string) $child->title], $depth + 1];
            }
        }

        return $out;
    }

    /** @param  list<string>  $trail */
    private function project(BeamUxEntry $entry, array $trail, int $depth): array
    {
        [$excerpt, $content] = $this->textOf($entry);
        preg_match_all('/^#{1,6}\s+(.+)$/m', $content, $headings);

        return [
            'id' => (string) $entry->getKey(),
            'title' => (string) $entry->title,
            'trail' => $trail,
            'excerpt' => $excerpt,
            'headings' => implode("\n", $headings[1] ?? []),
            'text' => $this->plain($content),
            'depth' => $depth,
            'order' => (int) ($entry->nav_order ?? 0),
            'template' => $entry->template,
        ];
    }

    /** @return array{0: ?string, 1: string} the excerpt and the body source, both from the PUBLISHED body */
    private function textOf(BeamUxEntry $entry): array
    {
        $body = $this->publishedBody($entry);

        if (is_array($body) && array_key_exists('content', $body)) {
            $front = is_array($body['frontmatter'] ?? null) ? $body['frontmatter'] : [];
            $excerpt = $front['excerpt'] ?? $front['description'] ?? null;

            return [is_string($excerpt) ? $excerpt : null, (string) $body['content']];
        }

        if (is_string($body)) {
            return [null, $body];
        }

        try {
            return [null, $body === null ? '' : (string) $entry->codec()->decode($body)];
        } catch (Throwable) {
            return [null, ''];
        }
    }

    private function publishedBody(BeamUxEntry $entry): mixed
    {
        if ($entry->particle_id === null) {
            return null;
        }

        $pin = $entry->getAttribute('published_version');

        if ($pin !== null && $pin !== '') {
            // A key-only handle is all the version store needs to address the particle's history; no row read.
            $particle = (new BeamParticle)->newInstance([], true);
            $particle->setAttribute($particle->getKeyName(), $entry->particle_id);
            $version = $this->versions->version($particle, (string) $pin);

            return $version?->snapshot['payload'] ?? null;
        }

        return $this->drivers->resolve($entry)->read((string) $entry->particle_id)?->body;
    }

    /** MDX source to searchable words: drop imports, exports, tags, expressions and markdown punctuation. */
    private function plain(string $source): string
    {
        $text = preg_replace('/^\s*(import|export)\s.*$/m', ' ', $source) ?? $source;
        $text = preg_replace('/<[^>]*>|\{[^}]*\}|[#*_`>\[\]()!|~-]+/', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
