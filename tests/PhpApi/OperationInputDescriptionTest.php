<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Schemastud\DataSchemas\Generators\Generator;
use Splicewire\Beam\Docs\Data\DocsSearchInputData;
use Splicewire\Beam\Docs\Data\PhpApiSymbolInputData;
use Splicewire\Beam\Docs\Tests\TestCase;

/**
 * Every property of a beam-docs operation `input:` carries a description in the request schema, read through the same
 * `Generator::forRequest()` call a host's reference and generated SDK use. A host guards this across all of its
 * operations (the flagship's ParticleOperationInputCoverageTest); this keeps beam-docs' own inputs from tripping it.
 * `php-api-index` takes no properties, so it is not listed. `php-api-symbol`'s `fqn` shipped without a description;
 * the flagship gate found it on 2026-11-09.
 */
class OperationInputDescriptionTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function inputs(): array
    {
        return [
            'beam-docs.php-api-symbol' => [PhpApiSymbolInputData::class],
            'beam-docs.search' => [DocsSearchInputData::class],
        ];
    }

    /** @param  class-string  $input */
    #[DataProvider('inputs')]
    public function test_every_input_property_is_described(string $input): void
    {
        $generator = app(Generator::class)->forRequest();
        $reflection = new ReflectionClass($input);

        $this->assertTrue($generator->canGenerate($reflection), $input.' cannot be described by the request generator.');

        $schema = $generator->generate($reflection);
        $properties = (array) ($schema['properties'] ?? []);

        $this->assertNotEmpty($properties, $input.' has no request properties to describe.');

        foreach ($properties as $name => $property) {
            $this->assertNotSame('', trim((string) ($property['description'] ?? '')), sprintf('%s::$%s has no description.', $input, $name));
        }
    }
}
