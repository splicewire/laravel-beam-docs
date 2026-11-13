<?php

namespace Splicewire\Beam\Docs\Routing;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use LogicException;
use Splicewire\Beam\Docs\Http\DocsReadAccess;
use Splicewire\Beam\Docs\Http\PhpApiReadGate;
use Splicewire\Beam\Docs\PhpApi\PhpApiIndexOp;
use Splicewire\Beam\Docs\PhpApi\PhpApiSymbolOp;
use Splicewire\Beam\Docs\Publishing\Http\EnsurePublishingHost;
use Splicewire\Beam\Docs\Publishing\Http\PublicationController;
use Splicewire\Beam\Docs\Search\DocsSearchOp;
use Splicewire\Beam\Facades\Particle;
use Splicewire\Beam\Http\OpenApiSpecController;
use Splicewire\Beam\Http\Particle\ParticleOperationController;
use Splicewire\Beam\Routing\ApiRoot;

final class DocsRoutes
{
    // Ticket 16 restores beam.docs. after legacy exposures go away.
    private const NAME_PREFIX = 'beam.docs.api.';

    public static function mount(): void
    {
        ApiRoot::mount(app(Router::class), 'beam.docs.api_root', 'docs', function (Router $router): void {
            $router->name(self::NAME_PREFIX)->group(function (): void {
                self::openApi(guard: true);
                self::publications(guard: true);
                self::search(guard: true);
            });
        });
    }

    /** @internal The pre-expand artifact exposure, kept until the legacy roots are removed. */
    public static function legacyOpenApi(): void
    {
        Route::prefix(self::legacyOpenApiRoot())->name('beam.')->group(fn () => self::openApi());
    }

    /** @internal The pre-expand publication exposure, kept until the legacy roots are removed. */
    public static function legacyPublications(): void
    {
        Route::prefix(ApiRoot::for('beam.docs.legacy_root', 'docs'))->name('beam.docs.')->group(fn () => self::publications());
    }

    /** @internal The pre-expand docs UX operation exposure, kept until the legacy roots are removed. */
    public static function legacySearch(): void
    {
        Route::prefix(ApiRoot::for('beam.docs.legacy_root', 'docs'))->group(fn () => self::search());
    }

    private static function legacyOpenApiRoot(): string
    {
        $root = config('beam.docs.legacy_openapi_root');
        if (! is_string($root) || trim($root, '/') === '') {
            $root = (require dirname(__DIR__, 2).'/config/beam/docs.php')['legacy_openapi_root'];
        }

        return trim($root, '/');
    }

    private static function openApi(bool $guard = false): void
    {
        $middleware = config('beam.docs.openapi.middleware') ?? config('beam.core.openapi.middleware', []);
        Route::middleware(['web', DocsReadAccess::class, ...(array) $middleware])->group(function () use ($guard): void {
            foreach (['yaml', 'json'] as $format) {
                self::route('GET', 'openapi.'.$format, [OpenApiSpecController::class, $format], 'openapi.'.$format, guard: $guard);
            }
        });
    }

    private static function publications(bool $guard = false): void
    {
        Route::middleware(['web', EnsurePublishingHost::class])->prefix('publications')->name('publications.')->group(function () use ($guard): void {
            self::route('GET', '', [PublicationController::class, 'index'], 'index', guard: $guard);
            self::route('POST', '', [PublicationController::class, 'store'], 'store', guard: $guard);
            self::route('GET', '{publication}', [PublicationController::class, 'show'], 'show', guard: $guard)->whereUuid('publication');
            self::route('POST', '{publication}/retry', [PublicationController::class, 'retry'], 'retry', guard: $guard)->whereUuid('publication');
        });
    }

    private static function search(bool $guard = false): void
    {
        Route::middleware(['web', DocsReadAccess::class, 'throttle:60,1'])->group(function () use ($guard): void {
            self::op('search', DocsSearchOp::class, $guard);
            Route::middleware(PhpApiReadGate::class)->group(function () use ($guard): void {
                self::op('php-api-index', PhpApiIndexOp::class, $guard);
                self::op('php-api-symbol', PhpApiSymbolOp::class, $guard);
            });
        });
    }

    private static function op(string $name, string $class, bool $guard): void
    {
        if ($guard && self::existing('GET', $name, [ParticleOperationController::class, 'invoke'], [ParticleOperationController::RESOURCE => 'beam-docs', ParticleOperationController::NAME => $name])) {
            return;
        }
        Particle::ops('', 'beam-docs', [$class]);
    }

    private static function route(string $method, string $uri, array $action, string $name, bool $guard = false): \Illuminate\Routing\Route
    {
        return ($guard ? self::existing($method, $uri, $action) : null) ?? Route::match($method === 'GET' ? ['GET', 'HEAD'] : [$method], $uri, $action)->name($name);
    }

    /** Reuse an identical default exposure; an override adds a twin without mutating legacy routes. */
    private static function existing(string $method, string $uri, array $action, array $defaults = []): ?\Illuminate\Routing\Route
    {
        $router = app(Router::class);
        $groups = $router->getGroupStack();
        $group = $groups === [] ? [] : $groups[array_key_last($groups)];
        $path = trim(($group['prefix'] ?? '').'/'.$uri, '/');
        foreach ($router->getRoutes() as $existing) {
            if ($existing->uri() !== $path || $existing->getDomain() !== ($group['domain'] ?? null) || ! in_array($method, $existing->methods(), true)) {
                continue;
            }
            if ($existing->getActionName() === $action[0].'@'.$action[1]
                && Router::uniqueMiddleware($existing->middleware()) === Router::uniqueMiddleware((array) ($group['middleware'] ?? []))
                && array_diff_assoc($defaults, $existing->defaults) === []) {
                return $existing;
            }
            throw new LogicException('Docs exposure conflicts with existing route ['.($existing->getName() ?? $path).'].');
        }

        return null;
    }
}
