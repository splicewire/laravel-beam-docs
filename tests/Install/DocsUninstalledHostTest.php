<?php

namespace Splicewire\Beam\Docs\Tests\Install;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DocsUninstalledHostTest extends TestCase
{
    public function test_retained_published_scribe_config_loads_without_documentation_classes(): void
    {
        $directory = sys_get_temp_dir().'/beam-docs-uninstalled-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $published = $directory.'/scribe.php';
        copy(dirname(__DIR__, 2).'/stubs/scribe/scribe.php', $published);

        try {
            // A separate PHP process has no package autoloader. Omitting a provider in this
            // suite would leave Scribe's classes available and could not prove removal safety.
            $process = new Process([PHP_BINARY, '-r', <<<'PHP'
                $classes = [
                    'Knuckles\\Scribe\\Config\\Defaults',
                    'Splicewire\\Beam\\Docs\\BeamDocsServiceProvider',
                    'Splicewire\\Beam\\Scribe\\OpenApi\\TagHierarchyGenerator',
                ];
                foreach ($classes as $class) {
                    if (class_exists($class)) {
                        throw new RuntimeException('The removed documentation class remains loadable: '.$class);
                    }
                }
                echo json_encode(require $argv[1], JSON_THROW_ON_ERROR);
                PHP, $published], $directory);
            $process->run();

            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame([], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
        } finally {
            unlink($published);
            rmdir($directory);
        }
    }
}
