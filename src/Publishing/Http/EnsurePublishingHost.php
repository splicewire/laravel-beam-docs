<?php

namespace Splicewire\Beam\Docs\Publishing\Http;

use Closure;
use Illuminate\Http\Request;
use Splicewire\Beam\Docs\Publishing\PublicationFailure;
use Splicewire\Beam\Docs\Publishing\PublicationPolicy;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublishingHost
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            app(PublicationPolicy::class)->assertHostContext();
        } catch (PublicationFailure) {
            abort(403);
        }

        return $next($request);
    }
}
