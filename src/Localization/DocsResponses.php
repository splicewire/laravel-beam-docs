<?php

namespace Splicewire\Beam\Docs\Localization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class DocsResponses
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() || ! $exception instanceof DocsHttpException) {
            return null;
        }
        $error = $exception->explicitCodedError();
        $source = $exception->getPrevious();
        if ($error === null || ! $source instanceof CodedPublicationFailure || $source->explicitCodedError() !== $error) {
            return null;
        }

        return new JsonResponse((new DocsErrorData(
            $error->message($source->messageParameters()), $error->code(),
        ))->toArray(), $exception->getStatusCode(), $exception->getHeaders(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
