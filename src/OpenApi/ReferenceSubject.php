<?php

namespace Splicewire\Beam\Docs\OpenApi;

use InvalidArgumentException;
use Stringable;

/**
 * What a docs root's API reference documents (docs-walkthrough DM7, DOC-12): `self`, this host's own routes (the default,
 * and right for a starter, C-7), or `product:vendor/name`, a product the root documents, whose reference needs a declared
 * artifact (`beam.docs.openapi.artifact`). Read from `beam.docs.openapi.subject`.
 */
class ReferenceSubject implements Stringable
{
    private function __construct(public readonly ?string $product) {}

    public static function fromConfig(): self
    {
        return self::parse((string) (config('beam.docs.openapi.subject') ?? 'self'));
    }

    public static function parse(string $value): self
    {
        $value = trim($value);

        if ($value === '' || $value === 'self') {
            return new self(null);
        }

        if (preg_match('/^product:\s*([a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*)$/i', $value, $m) === 1) {
            return new self($m[1]);
        }

        throw new InvalidArgumentException("beam.docs.openapi.subject must be `self` or `product:vendor/name`, got [{$value}].");
    }

    public function isProduct(): bool
    {
        return $this->product !== null;
    }

    /** Whether the product's own spec is declared. A product subject without one publishes no reference. */
    public function hasArtifact(): bool
    {
        $artifact = config('beam.docs.openapi.artifact');

        return $this->isProduct() && is_string($artifact) && $artifact !== '';
    }

    public function __toString(): string
    {
        return $this->product === null ? 'self' : "product:{$this->product}";
    }
}
