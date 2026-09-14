<?php

namespace Splicewire\Beam\Docs\Http;

use Closure;
use Illuminate\Http\Request;
use Splicewire\Beam\Docs\Access\DocsAccess;

class DocsReadAccess
{
    public function __construct(private DocsAccess $access) {}

    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless($this->access->allows($request->user()), 404);
        $response = $next($request);
        // The same URL can become private or disabled; shared caches must not retain old bytes.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
