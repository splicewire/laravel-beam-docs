<?php

namespace Splicewire\Beam\Docs\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\OpenApi\ConfiguredArtifactSpecSource;
use Throwable;

/** Generate the host's public reference artifact after its Scribe configuration is published. */
class GenerateOpenApiCommand extends Command
{
    protected $signature = 'splicewire:beam:docs:generate';

    protected $description = 'Generate the public OpenAPI artifact served by Beam documentation';

    public function handle(ConfiguredArtifactSpecSource $source): int
    {
        if (! config('beam.docs.enabled', true)) {
            $this->components->info('Documentation is disabled; generation skipped.');

            return self::SUCCESS;
        }

        try {
            $published = config_path('scribe.php');

            if (is_file($published)) {
                $configuration = require $published;

                if (! is_array($configuration) || $configuration === []) {
                    $this->components->error('The published Scribe configuration is empty or invalid.');

                    return self::FAILURE;
                }

                config(['scribe' => $configuration]);
            }

            config(['scribe.laravel.add_routes' => false]);

            if ($this->call('scribe:generate') !== self::SUCCESS) {
                return self::FAILURE;
            }

            if (! is_file($source->artifactPath())) {
                $this->components->error('Generation produced no public-reference artifact at '.$source->artifactPath());

                return self::FAILURE;
            }
        } catch (Throwable $exception) {
            $this->components->error('OpenAPI generation failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Generated '.$source->artifactPath());

        return self::SUCCESS;
    }
}
