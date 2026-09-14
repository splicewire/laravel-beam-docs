<?php

namespace Splicewire\Beam\Docs\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Splicewire\Beam\Docs\Contracts\DocsFrontendContracts;

class ExportFrontendContractsCommand extends Command
{
    protected $signature = 'splicewire:beam:docs:export-contracts
        {--output= : Directory receiving the generated frontend contracts}
        {--write : Write the contracts; otherwise print a JSON filename-to-content map}';

    protected $description = 'Export documentation frontend types and schemas from their PHP Data declarations';

    public function handle(DocsFrontendContracts $contracts, Filesystem $filesystem): int
    {
        if (! $this->option('write')) {
            $this->line(json_encode($contracts->files(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $output = $this->option('output');
        if (! is_string($output) || trim($output) === '') {
            $this->error('--write requires an explicit --output directory.');

            return self::FAILURE;
        }

        $files = $contracts->files();
        $filesystem->ensureDirectoryExists($output);
        foreach ($files as $name => $contents) {
            if ($filesystem->put(rtrim($output, '/').'/'.$name, $contents) === false) {
                $this->error('Could not write '.$name.'.');

                return self::FAILURE;
            }
        }
        $this->info('Documentation frontend contracts exported.');

        return self::SUCCESS;
    }
}
