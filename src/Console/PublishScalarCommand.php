<?php

namespace Splicewire\Beam\Docs\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Splicewire\Beam\Docs\Publishing\Data\PublicationData;
use Splicewire\Beam\Docs\Publishing\PublicationFailure;
use Splicewire\Beam\Docs\Publishing\PublicationService;
use Throwable;

class PublishScalarCommand extends Command
{
    protected $signature = 'splicewire:beam:docs:publish {version? : Explicit release version} {--retry= : Retry a failed attempt using its captured artifact} {--status= : Read an attempt without publishing} {--json : Print the publication status as JSON}';

    protected $description = 'Publish the captured public-reference OpenAPI artifact to Scalar Registry';

    public function handle(PublicationService $publications): int
    {
        $modes = array_filter([$this->argument('version'), $this->option('retry'), $this->option('status')], fn ($value) => $value !== null && $value !== '');
        if (count($modes) !== 1) {
            $this->error('Provide exactly one release version, --retry, or --status.');

            return self::INVALID;
        }

        try {
            if ($this->option('status')) {
                $publication = $publications->find((string) $this->option('status'));
            } else {
                $publication = $this->option('retry')
                    ? $publications->retry((string) $this->option('retry'))
                    : $publications->capture((string) $this->argument('version'));
                $publication = $publications->run($publication->id);
            }
            $data = PublicationData::fromPublication($publication);
            $this->line($this->option('json') ? $data->toJson() : $publication->id.' '.$publication->status.($publication->error ? ': '.$publication->error : ''));

            return $this->option('status') || $publication->status === 'succeeded' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            // Validation/configuration errors are safe; infrastructure exceptions can contain secrets.
            $this->error($error instanceof PublicationFailure || $error instanceof ValidationException
                ? $error->getMessage() : 'Cannot publish this artifact. Check the attempt ID, database and worker configuration.');

            return self::FAILURE;
        }
    }
}
