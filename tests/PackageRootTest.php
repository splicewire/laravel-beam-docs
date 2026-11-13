<?php

namespace Splicewire\Beam\Docs\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Docs\Routing\DocsRoutes;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PackageRootTest extends TestCase
{
    public function test_default_root_exists_and_reuses_the_original_routes_and_names(): void
    {
        $routes = app('router')->getRoutes();
        $routes->refreshNameLookups();
        $count = count($routes);
        $names = ['beam.docs.publications.index', 'beam.docs.publications.store', 'beam.docs.publications.show', 'beam.docs.publications.retry', 'beam-docs.search', 'beam-docs.php-api-index', 'beam-docs.php-api-symbol'];
        $original = [];
        foreach ($names as $name) {
            $original[$name] = $routes->getByName($name);
            $this->assertNotNull($original[$name]);
        }
        DocsRoutes::mount();
        $routes->refreshNameLookups();
        $this->assertCount($count + 2, $routes);
        foreach ($original as $name => $route) {
            $this->assertSame($route, $routes->getByName($name));
            $this->assertSame($name, $route->getName());
        }
        foreach (['yaml', 'json'] as $format) {
            $new = $routes->match(Request::create('/beam/docs/openapi.'.$format));
            $old = $routes->match(Request::create('/beam/openapi.'.$format));
            $this->assertSame($old->getActionName(), $new->getActionName());
            $this->assertSame($old->gatherMiddleware(), $new->gatherMiddleware());
            $this->assertSame(['GET', 'HEAD'], $new->methods());
        }
        $this->assertSame([], collect($routes->getRoutes())->map(fn ($r) => $r->getName())->filter()->duplicates()->values()->all());
    }

    public function test_override_keeps_all_get_and_post_actions_gates_and_constraints(): void
    {
        config(['beam.docs.api_root' => 'custom/reference']);
        DocsRoutes::mount();
        $routes = app('router')->getRoutes();
        $id = '35fcd243-684f-4c02-80f9-c127ebdf34ba';
        foreach ([['publications', 'GET'], ['publications', 'POST'], ['publications/'.$id, 'GET'], ['publications/'.$id.'/retry', 'POST'], ['search', 'GET'], ['php-api-index', 'GET'], ['php-api-symbol', 'GET']] as [$path,$method]) {
            $new = $routes->match(Request::create('/custom/reference/'.$path, $method));
            $old = $routes->match(Request::create('/beam/docs/'.$path, $method));
            $this->assertSame($old->getActionName(), $new->getActionName());
            $this->assertSame($old->gatherMiddleware(), $new->gatherMiddleware());
            $this->assertSame($old->wheres, $new->wheres);
            $this->assertSame($old->methods(), $new->methods());
        }
        $this->assertSame([], collect($routes->getRoutes())->map(fn ($r) => $r->getName())->filter()->duplicates()->values()->all());
        $this->expectException(NotFoundHttpException::class);
        $routes->match(Request::create('/custom/reference/publications/not-a-uuid'));
    }

    public function test_conflicting_new_exposure_refuses_by_name(): void
    {
        config(['beam.docs.api_root' => 'conflicting/docs']);
        Route::get('conflicting/docs/openapi.yaml', fn () => [])->name('foreign.openapi');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('foreign.openapi');
        DocsRoutes::mount();
    }

    public function test_reuse_does_not_freeze_a_later_host_middleware_append(): void
    {
        $route = app('router')->getRoutes()->match(Request::create('/beam/docs/publications'));
        DocsRoutes::mount();
        $route->middleware('after-docs-mount');
        $this->assertContains('after-docs-mount', $route->gatherMiddleware());
    }

    public function test_missing_cached_legacy_root_keeps_the_original_openapi_address(): void
    {
        config(['beam.docs.legacy_openapi_root' => null]);
        DocsRoutes::legacyOpenApi();
        $this->assertSame('beam/openapi.yaml', app('router')->getRoutes()->match(Request::create('/beam/openapi.yaml'))->uri());
        $this->assertFalse(collect(app('router')->getRoutes()->getRoutes())->contains(fn ($route) => $route->uri() === 'openapi.yaml'));
    }
}
