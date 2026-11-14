<?php

namespace Splicewire\Beam\Docs\Localization;

use Splicewire\Beam\Docs\Publishing\PublicationFailure;
use Splicewire\Beam\Localization\CodedError;

/** Keep the worker's diagnostic English; only the immediate HTTP catch renders this identity. */
class CodedPublicationFailure extends PublicationFailure
{
    private ?DocsPublishingError $error = null;

    /** @var array<string, scalar> */
    private array $params = [];

    /** @param array<string, scalar> $params */
    public static function for(DocsPublishingError $error, array $params = []): self
    {
        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements[':'.$name] = (string) $value;
        }
        $exception = new self(strtr($error->defaultMessage(), $replacements));
        $exception->error = $error;
        $exception->params = $params;

        return $exception;
    }

    public function explicitCodedError(): ?CodedError
    {
        return $this->error;
    }

    /** @return array<string, scalar> */
    public function messageParameters(): array
    {
        return $this->params;
    }
}
