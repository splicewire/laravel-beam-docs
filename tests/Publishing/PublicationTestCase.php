<?php

namespace Splicewire\Beam\Docs\Tests\Publishing;

use Illuminate\Http\Request;
use Splicewire\Beam\Docs\Publishing\PublicationService;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\OpenApi\OpenApiSpec;
use Splicewire\Beam\OpenApi\OpenApiSpecSource;
use Splicewire\Beam\OpenApi\SpecFormat;

abstract class PublicationTestCase extends TestCase
{
    protected string $work;

    protected MutableSpecSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = sys_get_temp_dir().'/beam-docs-publication-test-'.bin2hex(random_bytes(8));
        mkdir($this->work, 0700);
        $this->source = new MutableSpecSource;
        $this->app->instance(OpenApiSpecSource::class, $this->source);
        config([
            'beam.docs.enabled' => true,
            'beam.docs.visibility' => 'public',
            'beam.docs.scalar.enabled' => true,
            'beam.docs.scalar.namespace' => 'test-team',
            'beam.docs.scalar.slug' => 'test-api',
            'beam.docs.scalar.token' => 'personal-secret-test-token',
            'beam.docs.scalar.executable' => $this->work.'/scalar',
            'beam.docs.scalar.process_timeout' => 5,
            'beam.docs.scalar.show_link' => true,
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);
        $migration = require dirname(__DIR__, 2).'/database/migrations/create_beam_docs_publications_table.php.stub';
        $migration->up();
        $this->fake([]);
        $script = file_get_contents(__DIR__.'/fixtures/scalar.php');
        // Herd's interpreter path contains spaces, which a Unix shebang cannot quote.
        symlink(PHP_BINARY, $this->work.'/php');
        file_put_contents($this->work.'/scalar', '#!'.$this->work.'/php'."\n".$script);
        chmod($this->work.'/scalar', 0700);
    }

    protected function tearDown(): void
    {
        if (isset($this->work)) {
            foreach (glob($this->work.'/*') as $path) {
                unlink($path);
            }
            rmdir($this->work);
        }
        parent::tearDown();
    }

    protected function service(): PublicationService
    {
        return $this->app->make(PublicationService::class);
    }

    /** @param array<string, mixed> $configuration */
    protected function fake(array $configuration): void
    {
        file_put_contents($this->work.'/control.json', json_encode($configuration));
    }

    /** @return array<array<string, mixed>> */
    protected function calls(): array
    {
        if (! is_file($this->work.'/calls.jsonl')) {
            return [];
        }

        return array_map(fn ($line) => json_decode($line, true), file($this->work.'/calls.jsonl', FILE_IGNORE_NEW_LINES));
    }
}

class MutableSpecSource implements OpenApiSpecSource
{
    public ?string $body = "openapi: 3.1.0\ninfo:\n  title: Captured API\n  version: 1.0.0\npaths: {}\n";

    public function spec(SpecFormat $format, Request $request): ?OpenApiSpec
    {
        return $this->body === null ? null : new OpenApiSpec($this->body, $format);
    }
}
