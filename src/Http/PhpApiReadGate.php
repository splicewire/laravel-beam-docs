<?php

namespace Splicewire\Beam\Docs\Http;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Splicewire\Beam\Docs\PhpApi\PhpApiSurface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware on the two PHP API read ops (php-api-reference slice 2). It runs BEFORE the particle controller
 * validates `input:`, so an off surface is the uniform 404 for every request shape: a direct call never learns, from a
 * 422, that the route exists. A non-string `fqn` is the same 404 (input is a key, never a structure). The ops check
 * {@see PhpApiSurface} again themselves, so the mount is not their only guard.
 */
class PhpApiReadGate
{
    public function __construct(private PhpApiSurface $surface) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($this->surface->readable($user instanceof Authenticatable ? $user : null), 404);
        abort_if($request->query->has('fqn') && ! is_string($request->query->all()['fqn']), 404);

        return $next($request);
    }
}
