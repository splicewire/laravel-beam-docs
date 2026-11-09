<?php

namespace Splicewire\Beam\Docs\Console;

use Illuminate\Console\Command;
use Splicewire\Beam\Docs\PhpApi\ArtifactWriter;
use Splicewire\Beam\Docs\PhpApi\PhpApiGenerator;

/**
 * Generate the PHP API reference artifact (php-api-reference slice 1). **This command is the only writer**: the
 * artifact is built here at build or install time and read later; nothing generates it on a request. Package code is
 * parsed statically and never loaded or executed.
 *
 * An empty `beam.docs.php_api.packages` is a no-op (it writes nothing): the list being empty is the "off" state, which
 * a reader takes from config, not from the artifact. A listed package that cannot be resolved is written into the
 * manifest as `unresolved` and fails the run, so a shorter artifact never reads like a complete one.
 */
class GeneratePhpApiCommand extends Command
{
    protected $signature = 'splicewire:beam:docs:php-api:generate
        {--write-to= : Write the artifact here instead of beam.docs.php_api.artifact}';

    protected $description = 'Generate the static PHP API reference artifact for the packages in beam.docs.php_api.packages.';

    public function handle(PhpApiGenerator $generator, ArtifactWriter $writer): int
    {
        $configured = (array) config('beam.docs.php_api.packages', []);

        if ($configured === []) {
            $this->components->info('No packages listed in beam.docs.php_api.packages: nothing to generate.');

            return self::SUCCESS;
        }

        $audience = self::audience();
        $artifact = $generator->build($configured, $audience, array_values((array) config('beam.docs.php_api.references', [])));
        $target = (string) ($this->option('write-to') ?: self::artifactPath());
        $writer->write($artifact, $target);

        $failed = false;

        foreach ($artifact['report']['packages'] as $package) {
            $line = "{$package['name']}: {$package['status']}, {$package['published']} published, {$package['files']} file(s), "
                .count($package['withheld']).' paragraph(s) withheld, '.$package['citationsStripped'].' citation(s) stripped';

            if ($package['status'] !== 'ok') {
                $failed = true;
                $this->components->error($line.' — '.implode('; ', $package['problems']));
            } elseif ($package['parseErrors'] !== [] || $package['problems'] !== []) {
                $this->components->warn($line.' — '.count($package['parseErrors']).' parse error(s); '.implode('; ', $package['problems']));
            } else {
                $this->components->info($line);
            }
        }

        $this->line("Wrote the {$audience} PHP API artifact to {$target} ({$artifact['manifest']['totals']['published']} published symbol(s)).");

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** `beam.docs.php_api.audience`, or from `beam.docs.visibility`: a public docs site gets `public`. */
    public static function audience(): string
    {
        $configured = config('beam.docs.php_api.audience');

        if (in_array($configured, ['public', 'internal'], true)) {
            return $configured;
        }

        return config('beam.docs.visibility', 'public') === 'public' ? 'public' : 'internal';
    }

    public static function artifactPath(): string
    {
        $configured = config('beam.docs.php_api.artifact');

        return is_string($configured) && $configured !== '' ? $configured : storage_path('app/beam-docs/php-api');
    }
}
