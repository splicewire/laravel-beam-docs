<?php

namespace Splicewire\Beam\Docs\Publishing;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/** Adapter for @scalar/cli 2.1.0; no shell, shared login state, or credential-bearing output. */
class ScalarCli
{
    public const VERSION = '2.1.0';

    private const OUTPUT_LIMIT = 1048576;

    public function publish(Publication $publication, callable $checkPolicy): string
    {
        $token = config('beam.docs.scalar.token');

        if (! is_string($token) || $token === '') {
            throw new PublicationFailure('A Scalar API key must be configured on the publishing worker.');
        }

        $directory = sys_get_temp_dir().'/beam-docs-scalar-'.bin2hex(random_bytes(16));

        if (! mkdir($directory, 0700)) {
            throw new PublicationFailure('Cannot create the private Scalar working directory.');
        }

        try {
            $artifact = $directory.'/openapi.yaml';
            if (file_put_contents($artifact, $publication->snapshot) !== strlen($publication->snapshot)) {
                throw new PublicationFailure('Cannot write the captured OpenAPI artifact.');
            }
            chmod($artifact, 0600);

            // HOME is the child process's actual isolated home. The parent environment and the
            // operator's ~/.scalar-config are untouched. Scalar 2.1.0 has no config-path option.
            $environment = ['HOME' => $directory, 'USERPROFILE' => $directory, 'SCALAR_API_KEY' => false, 'CI' => '1', 'NO_COLOR' => '1'];
            $version = trim($this->run(['--version'], $directory, $environment, 'version check'));
            if ($version !== self::VERSION) {
                throw new PublicationFailure('The publishing worker requires @scalar/cli '.self::VERSION.'.');
            }
            $this->run(['document', 'validate', $artifact], $directory, $environment, 'validation');
            $this->run(['auth', 'login'], $directory, [...$environment, 'SCALAR_API_KEY' => $token], 'authentication');

            // Upstream applies --private only to CREATE, ignoring it when adding a version.
            // Read the pinned CLI's access table and refuse existing mismatches before upload.
            $listing = $this->run(['registry', 'list', '--namespace', $publication->namespace], $directory, $environment, 'destination lookup');
            $this->assertDestinationVisibility($listing, $publication);
            $checkPolicy();

            $arguments = ['registry', 'publish', $artifact, '--namespace', $publication->namespace, '--slug', $publication->slug, '--version', $publication->version];
            if ($publication->is_private) {
                $arguments[] = '--private';
            }
            $output = $this->run($arguments, $directory, $environment, 'upload');
            $url = self::registryUrl($publication);

            if (! preg_match('~(?<!\S)'.preg_quote($url, '~').'(?![^\s])~', $output)) {
                throw new PublicationFailure('Scalar returned success without the expected Registry URL. Inspect the remote version before retrying.');
            }

            return $url;
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public static function registryUrl(Publication $publication): string
    {
        // Values were restricted to safe URI-path characters at capture; the pinned CLI prints
        // the version literally (including SemVer's +build), without percent-encoding it.
        return 'https://registry.scalar.com/@'.$publication->namespace.'/apis/'.$publication->slug.'@'.$publication->version;
    }

    private function assertDestinationVisibility(string $output, Publication $publication): void
    {
        $empty = 'No registry APIs found for namespace "'.$publication->namespace.'".';
        if (str_contains($output, $empty)) {
            return;
        }

        // The 2.1.0 CLI exposes a table, not a JSON flag. Unknown/truncated output is an error.
        if (! preg_match('/Title\s*│\s*Name\s*│\s*Version\s*│\s*Access/u', $output)) {
            throw new PublicationFailure('Scalar destination visibility could not be verified.');
        }

        $foundRows = false;
        foreach (explode("\n", $output) as $line) {
            if (! str_contains($line, '@')) {
                continue;
            }

            $cells = array_map('trim', explode('│', trim($line)));
            if (count($cells) !== 6 || ! preg_match('~\A@[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*\z~', $cells[2]) || ! in_array($cells[4], ['Public', 'Private'], true)) {
                throw new PublicationFailure('Scalar destination visibility could not be verified.');
            }
            $foundRows = true;
            if ($cells[2] === '@'.$publication->namespace.'/'.$publication->slug && ($cells[4] === 'Private') !== $publication->is_private) {
                throw new PublicationFailure('The existing Scalar API visibility does not match this snapshot. Configure the Registry access before publishing.');
            }
        }

        if (! $foundRows) {
            throw new PublicationFailure('Scalar destination visibility could not be verified.');
        }
    }

    /** @param array<string> $arguments @param array<string, string|false> $environment */
    private function run(array $arguments, string $directory, array $environment, string $stage): string
    {
        $executable = config('beam.docs.scalar.executable', base_path('node_modules/.bin/scalar'));
        if (! is_string($executable) || $executable === '') {
            throw new PublicationFailure('Configure the installed Scalar CLI executable.');
        }
        $process = new Process([$executable, ...$arguments], $directory, $environment);
        $process->setTimeout(max(0.1, min(30, (float) config('beam.docs.scalar.process_timeout', 30))));
        $process->setInput('');
        $output = '';

        try {
            $process->run(function (string $type, string $chunk) use (&$output, $process): void {
                $output .= $chunk;
                // Symfony otherwise retains every byte independently of this bounded parser.
                $process->clearOutput();
                $process->clearErrorOutput();
                if (strlen($output) > self::OUTPUT_LIMIT) {
                    $process->stop(0);
                    throw new PublicationFailure('Scalar produced excessive output.');
                }
            });
        } catch (ProcessTimedOutException) {
            throw new PublicationFailure('Scalar '.$stage.' timed out. Check worker connectivity and inspect the remote version before retrying an upload.');
        }

        if (! $process->isSuccessful()) {
            $reason = match (true) {
                (bool) preg_match('/already exists|conflict|duplicate|409/i', $output) => 'The version already exists; choose a new version. Existing versions are never overwritten.',
                (bool) preg_match('/unauthorized|forbidden|invalid token|401|403/i', $output) => 'Check the worker API key and destination permissions.',
                default => 'Check the generated document, destination configuration and worker connectivity.',
            };
            // Never persist upstream stdout/stderr: authentication errors can contain exchanged
            // access tokens that are not the configured personal token and cannot be safely redacted.
            throw new PublicationFailure('Scalar '.$stage.' failed (exit '.$process->getExitCode().'). '.$reason);
        }

        return preg_replace('/\x1b\[[0-?]*[ -\/]*[@-~]/', '', $output) ?? '';
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new \FilesystemIterator($directory) as $item) {
            if ($item->isDir() && ! $item->isLink()) {
                $this->removeDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}
