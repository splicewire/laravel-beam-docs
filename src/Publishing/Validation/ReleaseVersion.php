<?php

namespace Splicewire\Beam\Docs\Publishing\Validation;

use Attribute;
use Spatie\LaravelData\Attributes\Validation\Regex;

/** One pattern drives server validation and its JSON-Schema projection. */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class ReleaseVersion extends Regex
{
    // The negative lookahead means absolute end-of-input in both PCRE and JavaScript;
    // PHP's \z is not a JavaScript anchor, and $ admits a trailing newline in both.
    public const PATTERN = '^[A-Za-z0-9][A-Za-z0-9._+-]*(?![\s\S])';

    public function __construct()
    {
        parent::__construct('/'.self::PATTERN.'/');
    }

    /**
     * The JSON-Schema projection, named in `data-schemas.validation_mapping` by `'Class::method'` string rather than a
     * closure, so a host's `config:cache` can serialize it (launch 00 nomination 82a861ca).
     */
    public static function jsonSchema(): array
    {
        return ['pattern' => self::PATTERN];
    }
}
