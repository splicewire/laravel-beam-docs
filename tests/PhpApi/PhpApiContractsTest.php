<?php

namespace Splicewire\Beam\Docs\Tests\PhpApi;

use Illuminate\Support\Facades\Route;
use Knuckles\Scribe\Matching\RouteMatcher;
use Splicewire\Beam\Docs\Contracts\DocsFrontendContracts;
use Splicewire\Beam\Docs\Http\DocsReadAccess;
use Splicewire\Beam\Docs\PhpApi\PhpApiIndexOp;
use Splicewire\Beam\Docs\PhpApi\PhpApiSymbolOp;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Particle\Attributes\ParticleOp;

/**
 * php-api-reference slice 2: the two read ops are docs routes, not API resources. They mount beside `docs.search`
 * (`web` + `DocsReadAccess` + a throttle, never a tenant API group), declare `ability: false` / `affordance: false`
 * because the entry gate is their authorization, stay out of the published OpenAPI reference, and their Data reaches
 * the frontend contracts.
 */
class PhpApiContractsTest extends TestCase
{
    public function test_both_ops_mount_beside_docs_search_with_the_docs_gate_and_a_throttle(): void
    {
        foreach (['beam/docs/php-api-index', 'beam/docs/php-api-symbol'] as $uri) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === $uri);
            $this->assertNotNull($route, $uri);
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $middleware = $route->gatherMiddleware();
            $this->assertContains('web', $middleware);
            $this->assertContains(DocsReadAccess::class, $middleware);
            $this->assertContains('throttle:60,1', $middleware);
        }
    }

    public function test_both_ops_declare_no_ability_and_no_affordance(): void
    {
        foreach ([PhpApiIndexOp::class => 'php-api-index', PhpApiSymbolOp::class => 'php-api-symbol'] as $class => $name) {
            $op = (new \ReflectionClass($class))->getAttributes(ParticleOp::class)[0]->newInstance();
            $this->assertSame('beam-docs', $op->resource);
            $this->assertSame($name, $op->name);
            $this->assertFalse($op->ability, 'bool false, not the string the generator emits for --ability=false');
            $this->assertFalse($op->affordance);
        }
    }

    public function test_the_ops_never_enter_the_published_openapi_reference(): void
    {
        $stub = require dirname(__DIR__, 2).'/stubs/scribe/scribe.php';
        $uris = array_map(fn ($matched) => $matched->getRoute()->uri(), (new RouteMatcher)->getRoutes($stub['routes']));

        $this->assertContains('beam/docs/php-api-index', array_map(fn ($r) => $r->uri(), Route::getRoutes()->getRoutes()), 'the route exists, so its absence below is a real exclusion');
        $this->assertNotContains('beam/docs/php-api-index', $uris);
        $this->assertNotContains('beam/docs/php-api-symbol', $uris);
        $this->assertSame([], array_values(array_filter($uris, fn ($uri) => str_starts_with($uri, 'beam/docs/'))));
    }

    public function test_the_wire_types_reach_the_frontend_contracts(): void
    {
        $types = app(DocsFrontendContracts::class)->files()['types.ts'];

        foreach (['PhpApiIndexData', 'PhpApiPackageData', 'PhpApiSymbolRowData', 'PhpApiSymbolData', 'PhpApiMemberData', 'PhpApiLinkData', 'PhpApiInheritedData'] as $type) {
            $this->assertStringContainsString('export type '.$type.' ', $types, $type);
        }
        $this->assertStringContainsString('audience: string', $types);
    }
}
