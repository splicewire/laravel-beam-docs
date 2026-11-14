<?php

namespace Splicewire\Beam\Docs\Localization;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Splicewire\Beam\Localization\CodedError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class DocsHttpException extends HttpException
{
    private ?DocsPublishingError $error = null;

    /** @var array<string, scalar> */
    private array $params = [];

    /** @param array<string, scalar> $params */
    public static function for(int $status, DocsPublishingError $error, array $params = [], ?Throwable $previous = null, array $headers = []): self
    {
        $raw = CodedPublicationFailure::for($error, $params)->getMessage();
        $exception = new self($status, $raw, $previous, $headers);
        $exception->error = $error;
        $exception->params = $params;

        return $exception;
    }

    public function explicitCodedError(): ?CodedError
    {
        return $this->error;
    }

    public function render(Request $request): ?JsonResponse
    {
        return DocsResponses::render($this, $request);
    }
}
