<?php

namespace Splicewire\Beam\Docs\Tests\Install;

use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Facades\Beam;
use Splicewire\Beam\Scribe\Strategies\GroupStrategy;
use Splicewire\Beam\Scribe\Strategies\ModelsResponseEnvelope;
use Splicewire\Beam\Scribe\Strategies\ParticleRequestStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleResponseStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleTitleStrategy;
use Splicewire\Beam\Scribe\Strategies\ReturnsResponseStrategy;
use Splicewire\Beam\Scribe\Strategies\RouteTitleStrategy;

class DocsInstallTest extends TestCase
{
    public function test_the_scribe_stub_publishes_beams_emitter_only_defaults(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'beam-scribe'])->assertExitCode(0);

        $published = config_path('scribe.php');
        $this->assertFileExists($published);

        $config = require $published;

        $this->assertSame('laravel', $config['type']);
        $this->assertFalse($config['laravel']['add_routes']);
        $this->assertTrue($config['openapi']['enabled']);
        $this->assertFalse($config['postman']['enabled']);
        // The exposure boundary is DERIVED, not the literal `['api/*']` this asserted before ADR-0211 §7
        // was amended: a bare beam install mounts no route under `api/*` at all, so that default made
        // every fresh host generate a spec describing nothing. `api/*` plus wherever this host's Frame
        // socket sits. The entry-body transport under `beam.ux.api_root` used to be prefixed here too; docs-walkthrough
        // DOC-12 (decided) makes it the CMS's own authoring API, never a public reference, so it is EXCLUDED instead
        // (an exclusion also catches it where a host mounts it under `api/`).
        $this->assertSame(
            ['api/*', 'frame/*'],
            $config['routes'][0]['match']['prefixes'],
        );
        $this->assertSame([], $config['routes'][0]['include']);
        $this->assertSame(['beam/ux/*'], $config['routes'][0]['exclude']);

        // The particle-aware extraction, without which a generated spec is bare paths (ADR-0211 §9).
        $this->assertContains(GroupStrategy::class, $config['strategies']['metadata']);
        $this->assertContains(ParticleTitleStrategy::class, $config['strategies']['metadata']);
        $this->assertContains(RouteTitleStrategy::class, $config['strategies']['metadata']);
        $this->assertContains(ParticleRequestStrategy::class, $config['strategies']['bodyParameters']);
        $this->assertContains(ReturnsResponseStrategy::class, $config['strategies']['responses']);
        $this->assertContains(ParticleResponseStrategy::class, $config['strategies']['responses']);

        // A trait the two response strategies share — listing it as a strategy would blow up extraction.
        $this->assertNotContains(ModelsResponseEnvelope::class, $config['strategies']['responses']);

        // Beam ships the taxonomy EMPTY: a host's groups derive from its own declared particle resources,
        // never from one estate's ontology seeded into every host (ADR-0211 §10).
        $this->assertSame([], $config['groups']['order']);

        @unlink($published);
    }
}
