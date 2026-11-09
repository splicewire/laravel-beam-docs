<?php

namespace Splicewire\Beam\Docs\Editor;

use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Schemastud\Blockdoc\Schema\ManifestBuilder;
use Splicewire\Beam\Docs\Editor\Nodes\PhpApiReference;

/**
 * The PHP API reference the seeded `docs-php-api` page places (`<PhpApiReference />`, php-api-reference slice 3).
 * Mirrors {@see ApiReferenceNodeSource}: it joins an MDX page's profile on a host that holds MDX as blocks
 * (laravel-beam-ux's `ManifestProfileResolver::MDX_SOURCES`), and no other page's.
 */
final class PhpApiReferenceNodeSource implements ManifestNodeSource
{
    public function profiles(): array
    {
        return ['content'];
    }

    public function nodeClassesFor(string $profile): array
    {
        return $profile === 'content' ? [PhpApiReference::class] : [];
    }

    public function docAdmitsFor(string $profile): ?array
    {
        return ['html'];
    }

    public function marksFor(string $profile): array
    {
        return [];
    }

    public function manifest(string $profile): array
    {
        $manifest = (new ManifestBuilder($this))->build($profile);
        foreach ($this->nodeClassesFor($profile) as $index => $class) {
            $manifest['nodes'][$index]['attrsSchema'] = $class::attrsSchema();
        }

        return $manifest;
    }
}
