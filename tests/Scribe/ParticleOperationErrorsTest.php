<?php

namespace Splicewire\Beam\Docs\Tests\Scribe;

use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Scribe\OpenApi\ParticleErrorsGenerator;
use Splicewire\Beam\Scribe\Strategies\ParticleResponseStrategy;

class OpSuccessFixtureData extends Data
{
    public function __construct(
        public string $id,
    ) {}
}

class OpRefusalFixtureData extends Data
{
    public function __construct(
        public string $code,
        public string $detail,
    ) {}
}

/**
 * An op's declared refusals reach the spec (music-capability-lift 34/35 prerequisite): each `errors:` status is stashed
 * beside the 200 as its Data's BARE body (as the op writes it), and only those responses are marked `x-particle-error`, so
 * a client generator types exactly what the op declared and never infers a refusal from a status or a `$ref` alone.
 */
class ParticleOperationErrorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(ParticleOperationRegistry::class);
    }

    private function endpoint(): ExtractedEndpointData
    {
        $route = (new Route(['POST'], 'catalogs/{id}/recalculate', [
            'uses' => ParticleOperationController::class.'@invoke',
            'controller' => ParticleOperationController::class.'@invoke',
        ]))
            ->defaults(ParticleOperationController::RESOURCE, 'catalogs')
            ->defaults(ParticleOperationController::NAME, 'recalculate');

        return ExtractedEndpointData::fromRoute($route);
    }

    /** @param  array<int, string>|null  $errors */
    private function registerOp(?array $errors): void
    {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'catalogs',
            name: 'recalculate',
            kind: OperationKind::Write,
            handle: fn () => null,
            output: OpSuccessFixtureData::class,
            errors: $errors,
        ));
    }

    private function strategy(): ParticleResponseStrategy
    {
        return new ParticleResponseStrategy(new DocumentationConfig([]));
    }

    public function test_a_declared_refusal_is_stashed_as_its_bare_body_beside_the_200(): void
    {
        $this->registerOp([422 => OpRefusalFixtureData::class]);
        $endpoint = $this->endpoint();

        $responses = $this->strategy()($endpoint);

        $this->assertSame([200, 422], array_column($responses, 'status'));
        $stash = array_column($endpoint->custom['dataResponseSchemas'], null, 'status');
        $this->assertSame(['$ref' => '#/$defs/OpSuccessFixtureData'], $stash[200]['schema']['properties']['data']);
        // Bare: the Data itself, no envelope, exactly the body the op writes.
        $this->assertSame('OpRefusalFixtureData', $stash[422]['schema']['title']);
        $this->assertSame(['code', 'detail'], $stash[422]['schema']['required']);
        $this->assertSame([422], $endpoint->custom[ParticleErrorsGenerator::STASH]);
    }

    public function test_an_op_declaring_no_refusal_stashes_its_200_only(): void
    {
        $this->registerOp(null);
        $endpoint = $this->endpoint();

        $this->assertSame([200], array_column($this->strategy()($endpoint), 'status'));
        $this->assertSame([200], array_column($endpoint->custom['dataResponseSchemas'], 'status'));
        $this->assertArrayNotHasKey(ParticleErrorsGenerator::STASH, $endpoint->custom);
    }

    public function test_only_the_declared_statuses_are_marked_in_the_document(): void
    {
        $this->registerOp([422 => OpRefusalFixtureData::class]);
        $endpoint = $this->endpoint();
        $this->strategy()($endpoint);
        $pathItem = ['responses' => [
            '200' => ['description' => 'ok'],
            '422' => ['description' => 'refused'],
            '500' => ['description' => 'a generic error the op did not declare'],
        ]];

        $marked = (new ParticleErrorsGenerator(new DocumentationConfig([])))
            ->pathItem($pathItem, [], OutputEndpointData::fromExtractedEndpointArray($endpoint->toArray()));

        $this->assertTrue($marked['responses']['422']['x-particle-error']);
        $this->assertArrayNotHasKey('x-particle-error', $marked['responses']['200']);
        $this->assertArrayNotHasKey('x-particle-error', $marked['responses']['500']);
    }

    public function test_an_endpoint_with_no_declared_refusal_is_left_untouched(): void
    {
        $this->registerOp(null);
        $endpoint = $this->endpoint();
        $this->strategy()($endpoint);
        $pathItem = ['responses' => ['200' => ['description' => 'ok'], '422' => ['description' => 'validation']]];

        $this->assertSame($pathItem, (new ParticleErrorsGenerator(new DocumentationConfig([])))
            ->pathItem($pathItem, [], OutputEndpointData::fromExtractedEndpointArray($endpoint->toArray())));
    }
}
