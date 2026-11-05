<?php

namespace Splicewire\Beam\Docs\Editor;

use Schemastud\Blockdoc\Contracts\ManifestNodeSource;
use Schemastud\Blockdoc\Schema\ManifestBuilder;
use Splicewire\Beam\Docs\Editor\Nodes\DataExample;

/**
 * The data example a registry page places (`<DataExample source="…">` around two fenced code blocks). Like
 * {@see RegistryRegistrantsNodeSource}, it joins an MDX page's profile on a host that holds MDX as blocks
 * (laravel-beam-ux's `ManifestProfileResolver::MDX_SOURCES`), and no other page's.
 */
final class DataExampleNodeSource implements ManifestNodeSource
{
    public function profiles(): array
    {
        return ['content'];
    }

    public function nodeClassesFor(string $profile): array
    {
        return $profile === 'content' ? [DataExample::class] : [];
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
