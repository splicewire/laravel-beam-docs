<?php

namespace Splicewire\Beam\Docs\Tests\Scribe;

use Illuminate\Routing\Route;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Rushing\LaravelDataSchemasScribe\OpenApi\DataSchemaGenerator;
use Spatie\LaravelData\Data;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Rendering\DeclaresDelivery;
use Splicewire\Beam\Scribe\OpenApi\DeliveryGenerator;
use Splicewire\Beam\Scribe\OpenApi\ParticleErrorsGenerator;
use Splicewire\Beam\Scribe\Strategies\ParticleOperationDeliveryStrategy;
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

class OpRefusalFixtureDelivery implements DeclaresDelivery
{
    public function mediaTypes(): array
    {
        return ['application/pdf'];
    }

    public function deliveryHeaders(): array
    {
        return [];
    }

    public function defaultFormat(): ?string
    {
        return 'pdf';
    }

    public function formats(): array
    {
        return ['pdf', 'html'];
    }
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
    private function registerOp(?array $errors, ?string $delivery = null): void
    {
        app(ParticleOperationRegistry::class)->register(new ParticleOperation(
            resource: 'catalogs',
            name: 'recalculate',
            kind: OperationKind::Write,
            handle: fn () => null,
            output: OpSuccessFixtureData::class,
            errors: $errors,
            delivery: $delivery,
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
        // Bare: the Data itself is the body (no envelope), promoted to a definition so it publishes as a component, and the
        // provenance travels INSIDE the declared schema, so a response that replaces it loses the mark with it.
        $this->assertSame('#/$defs/OpRefusalFixtureData', $stash[422]['schema']['$ref']);
        $this->assertSame(['code', 'detail'], $stash[422]['schema']['$defs']['OpRefusalFixtureData']['required']);
        $this->assertTrue($stash[422]['schema']['x-particle-error']);
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

    /**
     * The document as Scribe assembles it: the operation strategies stash during extraction, then the bridge's
     * DataSchemaGenerator, DeliveryGenerator and ParticleErrorsGenerator write the path item in the host's order.
     *
     * @return array<string, mixed>
     */
    private function assemble(?array $errors, ?string $delivery = null): array
    {
        $this->registerOp($errors, $delivery);
        $endpoint = $this->endpoint();
        $this->strategy()($endpoint);
        if ($delivery !== null) {
            (new ParticleOperationDeliveryStrategy(new DocumentationConfig([])))($endpoint);
        }
        $output = OutputEndpointData::fromExtractedEndpointArray($endpoint->toArray());
        $config = new DocumentationConfig([]);
        $pathItem = (new DataSchemaGenerator($config))->pathItem(['responses' => []], [], $output);
        $pathItem = (new DeliveryGenerator($config))->pathItem($pathItem, [], $output);

        return (new ParticleErrorsGenerator($config))->pathItem($pathItem, [], $output);
    }

    public function test_the_assembled_document_publishes_the_declared_refusal_as_a_marked_component(): void
    {
        $responses = $this->assemble([422 => OpRefusalFixtureData::class])['responses'];

        $this->assertTrue($responses['422']['x-particle-error']);
        $this->assertSame('#/components/schemas/OpRefusalFixtureData', $responses['422']['content']['application/json']['schema']['$ref']);
        $this->assertArrayNotHasKey('x-particle-error', $responses['200']);
    }

    public function test_an_endpoint_with_no_declared_refusal_gains_no_mark(): void
    {
        $responses = $this->assemble(null)['responses'];

        $this->assertArrayNotHasKey('422', $responses);
        $this->assertArrayNotHasKey('x-particle-error', $responses['200']);
    }

    /**
     * review-r1 14:14Z: an op may declare `errors: [422 => …]` AND a delivery with a format axis, whose generic format
     * rejection DeliveryGenerator writes at 422 over the declared body. The final 422 is then NOT the declared refusal, so
     * it must not be marked as one: the mark follows the declared schema's identity, never the status alone.
     */
    public function test_a_delivery_422_that_replaced_the_declared_refusal_is_not_marked(): void
    {
        $responses = $this->assemble([422 => OpRefusalFixtureData::class], OpRefusalFixtureDelivery::class)['responses'];

        $this->assertArrayHasKey('422', $responses);
        $this->assertArrayNotHasKey('x-particle-error', $responses['422']);
        $this->assertNotSame('#/components/schemas/OpRefusalFixtureData', $responses['422']['content']['application/json']['schema']['$ref'] ?? null);
    }
}
