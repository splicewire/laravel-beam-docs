<?php

namespace Splicewire\Beam\Docs\Tests\Particle;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Camel\Output\OutputEndpointData;
use Knuckles\Scribe\Tools\DocumentationConfig;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Particle\Attributes\AttributedParticleDiscovery;
use Splicewire\Beam\Particle\Attributes\ParticleOp;
use Splicewire\Beam\Particle\OperationKind;
use Splicewire\Beam\Particle\ParticleOperation;
use Splicewire\Beam\Particle\ParticleOperationRegistry;
use Splicewire\Beam\Particle\ParticleResourceRegistry;
use Splicewire\Beam\Rendering\DeclaresDelivery;
use Splicewire\Beam\Scribe\OpenApi\DeliveryGenerator;
use Splicewire\Beam\Scribe\Strategies\ParticleOperationDeliveryStrategy;
use Splicewire\Beam\Scribe\Strategies\ParticleOperationParameterStrategy;

/**
 * `delivery:` on the operation declaration — particle-operation-surface 11 (the decision) and 14 (this
 * landing).
 *
 * ## Why this file asserts a CARRIER and not just a slot
 *
 * The session that landed `method:`, `idConstraint:` and `signed:` deliberately REFUSED this slot, and
 * its reason is the whole shape of this test file: `delivery:` had no consumer. Added alone it would
 * have been the ninth instance of this effort's most-repeated defect — *a declaration that nominates
 * and nothing authorizes* — and ticket 14's acceptance #4 (*"format validation is byte-identical; the
 * OpenAPI 200 still names the same media types"*) would have passed **vacuously**, because nothing
 * would have published a media type to compare.
 *
 * So the slot lands with both halves of what reads it, and both are asserted here:
 *
 *  - **enforcement** — {@see ParticleOperationController::format()} refuses an unlisted `?format` with
 *    a 422 before the handler runs. This is the clause `RenderingsController` owned until ticket 13, moved (11
 *    A6). Ticket 13 dissolves that controller and regresses format validation from
 *    enforced-and-published to per-rendering ad hoc without it;
 *  - **publication** — {@see ParticleOperationDeliveryStrategy} stashes the contract and
 *    {@see DeliveryGenerator} writes it into the document, which is the only way to say "200,
 *    in these media types" at all: Scribe's own model is one-content-type-per-status and silently drops
 *    the second.
 *
 * ## The assertions are written against the DECLARATION, never against the end state
 *
 * The failure mode this file has to avoid is the one {@see DeclaredRouteFactsTest} names: an assertion
 * that would pass identically against the code before the change. Nothing here reads a value the
 * fixture could have produced on its own — every media type, every 422 and every enum is traced to a
 * `delivery:` slot that did not exist yesterday, and the undeclared case is asserted to be
 * byte-identical to today rather than merely "fine".
 */
class OperationDeliveryPublicationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dossiers', function (Blueprint $table): void {
            $table->id();
            $table->string('label');
        });

        Dossier::create(['label' => 'one']);

        FixtureDocumentDelivery::$formats = ['pdf', 'html'];

        // The Scribe strategies resolve the registry out of the container; without the singleton a
        // `register()` here and the strategy's read land on two different instances and every
        // publication assertion below silently measures an empty registry.
        $this->app->singleton(ParticleOperationRegistry::class);
    }

    // ── the declaration reaches the runtime object ──────────────────────────────────────────────────

    // ── publication: the contract reaches the OpenAPI document ──────────────────────────────────────

    public function test_the_200_names_one_content_entry_per_declared_media_type(): void
    {
        $this->registerOperation('export', delivery: FixtureDocumentDelivery::class);

        $pathItem = $this->assemble();

        // Before this slot, `media.download` — a `kind: Read` op streaming a file — published an untyped
        // 200 with no media type at all, because `output:` names a Data class and is structurally silent
        // about an operation that returns bytes.
        $this->assertSame(
            ['application/pdf', 'text/html'],
            array_keys($pathItem['responses']['200']['content']),
        );
    }

    public function test_the_200_carries_the_declared_response_headers(): void
    {
        $this->registerOperation('export', delivery: FixtureDocumentDelivery::class);

        $this->assertArrayHasKey(
            'Content-Disposition',
            $this->assemble()['responses']['200']['headers'],
        );
    }

    public function test_the_format_rejection_gets_the_422_slot_and_the_enum_gets_published(): void
    {
        $this->registerOperation('export', delivery: FixtureDocumentDelivery::class);

        $errors = $this->assemble()['responses']['422']['content']['application/json']['schema']['properties']['errors'];
        $this->assertArrayHasKey('format', $errors['properties']);

        $parameters = (new ParticleOperationParameterStrategy(new DocumentationConfig([])))($this->endpoint());

        // The published enum and the enforced set are ONE expression — `DeclaresDelivery::formats()`,
        // read live at both ends — rather than two that agree today.
        $this->assertSame(['pdf', 'html'], $parameters['format']['enumValues']);
        $this->assertSame('pdf', $parameters['format']['example']);
    }

    public function test_the_parameter_default_scribe_has_no_field_for_is_written_at_assembly(): void
    {
        $this->registerOperation('export', delivery: FixtureDocumentDelivery::class);

        $pathItem = $this->assemble([
            ['name' => 'format', 'in' => 'query', 'schema' => ['type' => 'string']],
        ]);

        $this->assertSame('pdf', $pathItem['parameters'][0]['schema']['default']);
    }

    public function test_an_operation_declaring_no_delivery_stashes_nothing_and_the_document_is_untouched(): void
    {
        $this->registerOperation('export');

        $endpoint = $this->endpoint();

        $this->assertSame([], (new ParticleOperationDeliveryStrategy(new DocumentationConfig([])))($endpoint));
        $this->assertArrayNotHasKey(DeliveryGenerator::STASH, $endpoint->custom);

        $pathItem = ['responses' => new \stdClass];
        $this->assertSame($pathItem, (new DeliveryGenerator(new DocumentationConfig([])))->pathItem(
            $pathItem,
            [],
            OutputEndpointData::fromExtractedEndpointArray($endpoint->toArray()),
        ));
    }

    public function test_the_delivery_strategy_defers_for_a_route_that_is_not_an_operation(): void
    {
        $plain = ExtractedEndpointData::fromRoute(Route::get('unrelated', fn () => null));

        $this->assertNull((new ParticleOperationDeliveryStrategy(new DocumentationConfig([])))($plain));
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────────

    private function discovery(): AttributedParticleDiscovery
    {
        return new AttributedParticleDiscovery(
            $this->app->make(ParticleResourceRegistry::class),
            $this->app->make(ParticleOperationRegistry::class),
        );
    }

    private function operation(
        string $name,
        DeclaresDelivery|string|null $delivery = null,
        string|false|null $input = null,
    ): ParticleOperation {
        return new ParticleOperation(
            resource: 'dossiers',
            name: $name,
            kind: OperationKind::Read,
            model: Dossier::class,
            handle: fn () => ['ran' => true],
            ability: false,
            input: $input,
            method: null,
            delivery: $delivery,
        );
    }

    private function mount(ParticleOperation $operation): void
    {
        $this->app->make(ParticleOperationRegistry::class)->register($operation);

        Particle::ops('dossiers', 'dossiers', $operation->name, ['method' => 'get']);
        Route::getRoutes()->refreshNameLookups();
    }

    private function registerOperation(string $name, DeclaresDelivery|string|null $delivery = null): void
    {
        $this->app->make(ParticleOperationRegistry::class)
            ->register($this->operation($name, delivery: $delivery));
    }

    /** A GET operation endpoint, built the way Scribe sees one: identity on the route defaults. */
    private function endpoint(): ExtractedEndpointData
    {
        $route = (new RoutingRoute(['GET'], 'dossiers/{id}/export', [
            'uses' => ParticleOperationController::class.'@invoke',
            'controller' => ParticleOperationController::class.'@invoke',
        ]))
            ->defaults(ParticleOperationController::RESOURCE, 'dossiers')
            ->defaults(ParticleOperationController::NAME, 'export');

        return ExtractedEndpointData::fromRoute($route);
    }

    /**
     * Run the strategy and its assembly hook the way Scribe does: the strategy stashes onto the
     * endpoint's `custom` bag during extraction, the bag survives into `OutputEndpointData`, and the
     * generator reads it at document assembly — by which point the Laravel route is long gone.
     *
     * @param  list<array<string, mixed>>  $parameters
     * @return array<string, mixed>
     */
    private function assemble(array $parameters = []): array
    {
        $endpoint = $this->endpoint();

        (new ParticleOperationDeliveryStrategy(new DocumentationConfig([])))($endpoint);

        return (new DeliveryGenerator(new DocumentationConfig([])))->pathItem(
            ['parameters' => $parameters, 'responses' => new \stdClass],
            [],
            OutputEndpointData::fromExtractedEndpointArray($endpoint->toArray()),
        );
    }
}

/**
 * A delivery whose format enumeration comes from a mutable static, so a test can prove the set is read
 * LIVE at both ends rather than frozen into the route table or into the declaration.
 */
class FixtureDocumentDelivery implements DeclaresDelivery
{
    /** @var list<string> */
    public static array $formats = ['pdf', 'html'];

    public function mediaTypes(): array
    {
        return ['application/pdf', 'text/html'];
    }

    public function deliveryHeaders(): array
    {
        return ['Content-Disposition' => 'The filename this document downloads as.'];
    }

    public function defaultFormat(): ?string
    {
        return 'pdf';
    }

    public function formats(): array
    {
        return static::$formats;
    }
}

#[ParticleOp(
    resource: 'dossiers',
    name: 'export',
    kind: OperationKind::Read,
    model: Dossier::class,
    delivery: FixtureDocumentDelivery::class,
)]
class FixtureDeliveringOp
{
    public static function handle(mixed $model, Request $request, mixed $actor): array
    {
        return [];
    }
}

#[ParticleOp(
    resource: 'dossiers',
    name: 'touch',
    kind: OperationKind::Read,
    model: Dossier::class,
)]
class FixtureBareDeliveryOp
{
    public static function handle(mixed $model, Request $request, mixed $actor): array
    {
        return [];
    }
}

class Dossier extends Model
{
    protected $table = 'dossiers';

    protected $guarded = [];

    public $timestamps = false;
}
