<?php

namespace Splicewire\Beam\Docs\Registries;

/**
 * One root's row in the committed product-documentation catalog (registry-docs ticket 01, amended
 * ruling 2): the root, the family package that DECLARES it (ownership is computed from the
 * `#[IsRegistry]` census, never guessed), and its doc pointer — a conventional/override page, an
 * external URL, an exemption carrying its reason (+ pointer), or `null` while the root is not yet
 * covered. Coverage gaps stay visible rows rather than missing rows: the catalog is total over the
 * census, and "uncovered" is an answer the audits read, not an absence.
 */
class CatalogRow
{
    public const KIND_PAGE = 'page';

    public const KIND_EXTERNAL = 'external';

    public const KIND_EXEMPT = 'exempt';

    public function __construct(
        public readonly string $root,
        public readonly ?string $owner,
        /** One of the KIND_* constants, or null for an uncovered root. */
        public readonly ?string $kind,
        /** page: the source path inside the owning package (override or conventional). */
        public readonly ?string $path = null,
        /** page: the conventional public path; external: the URL. Overrides carry no URL — the serving host's entries table resolves their placement. */
        public readonly ?string $url = null,
        /** exempt: the mandatory reason. */
        public readonly ?string $reason = null,
        /** exempt: the pointer to the doc explaining the mechanism (mandatory for infrastructure/rebind-only roots, ruling 4). */
        public readonly ?string $pointer = null,
    ) {}

    /** @param  array<string, mixed>  $row  the decoded catalog JSON for one root */
    public static function fromArray(string $root, array $row): self
    {
        $doc = is_array($row['doc'] ?? null) ? $row['doc'] : null;
        $string = fn (string $key): ?string => is_string($doc[$key] ?? null) && $doc[$key] !== '' ? $doc[$key] : null;

        return new self(
            root: $root,
            owner: is_string($row['owner'] ?? null) ? $row['owner'] : null,
            kind: $doc === null ? null : ($string('kind') ?? null),
            path: $doc === null ? null : $string('path'),
            url: $doc === null ? null : $string('url'),
            reason: $doc === null ? null : $string('reason'),
            pointer: $doc === null ? null : $string('pointer'),
        );
    }

    /** @return array<string, mixed> the shape the catalog file commits */
    public function toArray(): array
    {
        $doc = match ($this->kind) {
            self::KIND_PAGE => array_filter(['kind' => $this->kind, 'path' => $this->path, 'url' => $this->url], fn ($v) => $v !== null),
            self::KIND_EXTERNAL => ['kind' => $this->kind, 'url' => $this->url],
            self::KIND_EXEMPT => array_filter(['kind' => $this->kind, 'reason' => $this->reason, 'pointer' => $this->pointer], fn ($v) => $v !== null),
            default => null,
        };

        return ['owner' => $this->owner, 'doc' => $doc];
    }
}
