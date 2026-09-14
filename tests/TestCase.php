<?php

namespace Splicewire\Beam\Docs\Tests;

use Knuckles\Scribe\ScribeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Rushing\DataFilters\ServiceProvider as DataFiltersServiceProvider;
use Rushing\DataNav\ServiceProvider as DataNavServiceProvider;
use Rushing\PermissionCascade\PermissionCascadeServiceProvider;
use Rushing\Popcorn\Laravel\PopcornServiceProvider;
use Rushing\Versioning\VersioningServiceProvider;
use Schemastud\DataSchemas\LaravelDataSchemasServiceProvider;
use Schemastud\Frame\FrameServiceProvider;
use Schemastud\JsonNs\Laravel\JsonNsServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Splicewire\Beam\BeamServiceProvider;
use Splicewire\Beam\Docs\BeamDocsServiceProvider;
use Splicewire\Beam\Sitemap\BeamSitemapServiceProvider;
use Splicewire\Beam\Ux\BeamUxServiceProvider;
use Splicewire\Beam\Workflows\BeamWorkflowsServiceProvider;

abstract class TestCase extends Orchestra
{
    /** Schema authority for fixtures that explicitly opt into versioned schema generation. */
    public const SCHEMA_AUTHORITY = 'https://beam.test/schemas';

    protected function getPackageProviders($app): array
    {
        return [
            FrameServiceProvider::class,
            BeamServiceProvider::class,
            BeamDocsServiceProvider::class,
            BeamUxServiceProvider::class,
            BeamSitemapServiceProvider::class,
            BeamWorkflowsServiceProvider::class,
            ScribeServiceProvider::class,
            ActivitylogServiceProvider::class,
            LaravelDataServiceProvider::class,
            VersioningServiceProvider::class,
            LaravelDataSchemasServiceProvider::class,
            PermissionCascadeServiceProvider::class,
            DataNavServiceProvider::class,
            JsonNsServiceProvider::class,
            PopcornServiceProvider::class,
            DataFiltersServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('d', 32)));

        $app['config']->set('data-schemas.base_uri', $this->schemaAuthority());
    }

    protected function schemaAuthority(): string|bool|null
    {
        return null;
    }
}
