<?php

namespace Splicewire\Beam\Docs\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Docs\Access\DocsAccess;
use Splicewire\Beam\Docs\Seed\DocsSeeder;
use Splicewire\Beam\OpenApi\OpenApiSpec;
use Splicewire\Beam\OpenApi\OpenApiSpecSource;
use Splicewire\Beam\OpenApi\SpecFormat;
use Splicewire\Beam\Ux\Access\EntryAccessGate;
use Splicewire\Beam\Ux\Access\TokenAccessGate;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

class DocsPrivacyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(EntryAccessGate::class, fn () => new TokenAccessGate);
        $this->app->bind(OpenApiSpecSource::class, fn () => new class implements OpenApiSpecSource
        {
            public function spec(SpecFormat $format, Request $request): ?OpenApiSpec
            {
                return new OpenApiSpec($format === SpecFormat::Yaml ? 'openapi: 3.1.0' : '{"openapi":"3.1.0"}', $format, 100);
            }
        });
    }

    public function test_public_private_and_disabled_apply_to_both_download_formats(): void
    {
        foreach (['yaml', 'json'] as $format) {
            config(['beam.docs.enabled' => true, 'beam.docs.visibility' => 'public']);
            $response = $this->get('/beam/openapi.'.$format)->assertOk();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            config(['beam.docs.visibility' => 'private']);
            $this->get('/beam/openapi.'.$format)->assertNotFound();
            $this->actingAs(new GenericUser(['id' => 1]));
            $this->get('/beam/openapi.'.$format)->assertOk();
            config(['beam.docs.enabled' => false]);
            $this->get('/beam/openapi.'.$format)->assertNotFound();
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_private_readers_use_host_tokens_and_an_empty_list_denies(): void
    {
        $reader = new GenericUser(['id' => 1]);
        config(['beam.docs.visibility' => 'private', 'beam.docs.reader_tokens' => ['auth']]);
        $access = app(DocsAccess::class);
        $this->assertFalse($access->allows(null));
        $this->assertTrue($access->allows($reader));
        config(['beam.docs.reader_tokens' => []]);
        $this->assertFalse($access->allows($reader));
        config(['beam.docs.visibility' => 'typo']);
        $this->assertFalse($access->allows($reader));
    }

    public function test_adopting_an_existing_docs_root_preserves_identity_content_path_and_rights(): void
    {
        Schema::create('beam_ux_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug');
            $table->string('namespace')->nullable();
            $table->string('segment')->nullable();
            $table->string('title')->nullable();
            $table->json('access')->nullable();
            $table->json('traverse')->nullable();
            $table->json('requirements')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        DB::table('beam_ux_entries')->insert([
            'id' => '01993800-0000-7000-8000-000000000001', 'slug' => 'docs', 'segment' => '/our-handbook',
            'title' => 'Our edited handbook', 'access' => '[]', 'traverse' => '["auth"]', 'requirements' => '["existing"]',
        ]);
        $seeder = new DocsSeeder;
        $root = $seeder->existingRoot();
        $before = $root->getAttributes();
        $seeder->adopt($root);
        $seeder->adopt($root->fresh());
        $after = $root->fresh();
        $this->assertSame(['existing', 'beam-docs'], $after->requirements);
        foreach (['id', 'slug', 'segment', 'title', 'access', 'traverse'] as $field) {
            $this->assertSame($before[$field], $after->getRawOriginal($field));
        }
        $this->assertSame(1, BeamUxEntry::query()->count());
    }
}
