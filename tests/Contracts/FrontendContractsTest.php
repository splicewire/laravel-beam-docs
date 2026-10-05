<?php

namespace Splicewire\Beam\Docs\Tests\Contracts;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Docs\Contracts\DocsFrontendContracts;
use Splicewire\Beam\Docs\Publishing\Data\PublicationData;
use Splicewire\Beam\Docs\Publishing\Data\PublishInputData;
use Splicewire\Beam\Docs\Publishing\Publication;
use Splicewire\Beam\Docs\Tests\TestCase;

class FrontendContractsTest extends TestCase
{
    public function test_projection_preserves_nullable_timestamps_and_the_wire_status(): void
    {
        $files = app(DocsFrontendContracts::class)->files();
        $this->assertStringContainsString('createdAt: string | null', $files['types.ts']);
        $this->assertStringContainsString("'queued' | 'running' | 'succeeded' | 'failed'", $files['types.ts']);
        $schema = json_decode($files['publication.schema.json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['string', 'null'], $schema['properties']['createdAt']['type']);
        $this->assertNotContains('snapshot', array_keys($schema['properties']));

        $publication = new Publication([
            'id' => 'attempt', 'version' => 'v1', 'status' => 'queued', 'sha256' => str_repeat('a', 64),
            'namespace' => 'docs', 'slug' => 'api', 'is_private' => true,
        ]);
        $data = PublicationData::fromPublication($publication)->toArray();
        $this->assertSame('queued', $data['status']);
        $this->assertNull($data['createdAt']);
    }

    public function test_export_uses_the_configured_schema_generator(): void
    {
        config(['data-schemas.schema_metadata.$schema' => false]);
        $files = app(DocsFrontendContracts::class)->files();
        $schema = json_decode($files['publish-input.schema.json'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('$schema', $schema);
        $this->assertSame('Release version', $schema['properties']['version']['title']);
        $this->assertSame(128, $schema['properties']['version']['maxLength']);
        $this->assertArrayHasKey('pattern', $schema['properties']['version']);
    }

    public function test_the_release_version_mapping_survives_config_cache(): void
    {
        // A host's config:cache var_exports every value; a closure here made the whole host uncacheable
        // (launch 00 nomination 82a861ca). The mapping is a static-method string, and it still projects the pattern.
        $mapping = config('data-schemas.validation_mapping')[\Splicewire\Beam\Docs\Publishing\Validation\ReleaseVersion::class];
        $this->assertIsString($mapping);
        $this->assertSame(['pattern' => \Splicewire\Beam\Docs\Publishing\Validation\ReleaseVersion::PATTERN], $mapping());
    }

    public function test_release_version_validation_keeps_strict_end_of_input_and_build_metadata(): void
    {
        $this->assertSame('v1.2.3+build.4', PublishInputData::validateAndCreate(['version' => 'v1.2.3+build.4'])->version);
        foreach (['', 'bad release', 'v1/', "v1\n", str_repeat('a', 129)] as $version) {
            try {
                PublishInputData::validateAndCreate(['version' => $version]);
                $this->fail('An invalid release version passed server validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('version', $exception->errors());
            }
        }
    }

    public function test_command_prints_without_writing_and_requires_an_explicit_output_to_write(): void
    {
        $directory = sys_get_temp_dir().'/beam-docs-contracts-'.bin2hex(random_bytes(8));
        try {
            $this->assertSame(0, Artisan::call('splicewire:beam:docs:export-contracts', ['--output' => $directory]));
            $files = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('types.ts', $files);
            $this->assertDirectoryDoesNotExist($directory);

            $this->assertSame(1, Artisan::call('splicewire:beam:docs:export-contracts', ['--write' => true]));
            $this->assertDirectoryDoesNotExist($directory);
            $this->assertSame(0, Artisan::call('splicewire:beam:docs:export-contracts', ['--write' => true, '--output' => $directory]));
            foreach ($files as $name => $contents) {
                $this->assertSame($contents, file_get_contents($directory.'/'.$name));
            }
        } finally {
            (new Filesystem)->deleteDirectory($directory);
        }
    }
}
