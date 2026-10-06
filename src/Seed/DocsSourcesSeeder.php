<?php

namespace Splicewire\Beam\Docs\Seed;

use Illuminate\Database\Seeder;
use RuntimeException;
use Splicewire\Beam\Ux\Disk\RegisterEntriesFromDisk;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Containment\EntryPathResolver;
use Splicewire\Beam\Ux\Type\UxType;
use Splicewire\Beam\Write\AsSystemWriter;

/**
 * Sources in the seed chain (docs-walkthrough DOCS-05, rule DOC-3): registers every file under every declared docs
 * source (`beam.docs.sources`) the way `splicewire:beam:ux:register-from-disk {path} --under= --type=` would, minus the
 * source's `ignore` globs, so a fresh `migrate:fresh --seed` leaves the `docs.unregistered` audit passing and no
 * operator command is required. This is the last hand registration (D-A2 §1): ticket 18a and ticket 54 each did it by
 * hand.
 *
 * Create-once, like the importer it drives (ADR-0209 §11): a file that already has a row is skipped, so a re-seed is a
 * no-op and an edit made in the CMS survives.
 *
 * It THROWS rather than reporting when it cannot account for a source — a declared source missing on disk, files with
 * no inferrable `type`, or bodies that registered but would not compile — because the host declared the source, so
 * each is the host contradicting its own config, and `splicewire:beam:seed` turns a throwing step into a non-zero exit
 * (beam-docs-satellite 46).
 *
 * Run by {@see DocsSeeder} after the docs root, so a source hung `under` the docs root finds it. It is not its own
 * manifest step: the manifest keys steps by package, and a second registration replaced DocsSeeder (DOCS-05 L1 finding).
 */
class DocsSourcesSeeder extends Seeder
{
    use AsSystemWriter;

    public function run(): void
    {
        foreach ((array) config('beam.docs.sources', []) as $source) {
            $this->register((array) $source);
        }
    }

    /** @param  array{path?: string, under?: ?string, type?: ?string, ignore?: list<string>, package?: ?string}  $source */
    private function register(array $source): void
    {
        $declared = (string) ($source['path'] ?? '');
        $root = str_starts_with($declared, '/') ? $declared : base_path($declared);
        if ($declared === '' || ! is_dir($root)) {
            throw new RuntimeException("Declared docs source [{$declared}] is missing on disk (beam.docs.sources).");
        }

        $type = isset($source['type']) ? UxType::tryFrom((string) $source['type']) : null;
        $under = $this->under($source['under'] ?? null, $declared);
        $ignore = array_values(array_map('strval', (array) ($source['ignore'] ?? [])));
        // A source may declare the package that ships it (DOCS-15, DM2): its rows then stamp
        // package:<vendor> so they re-assert from the package. A host disk source declares none and
        // stamps disk: as before (the package-default rule).
        $package = isset($source['package']) && $source['package'] !== '' ? (string) $source['package'] : null;

        // The importer is resolved INSIDE the swap: resolving it first would close over the deny-by-default gate
        // (RegisterFromDiskCommand records why).
        $result = $this->asSystemWriter(
            fn () => app(RegisterEntriesFromDisk::class)->scan($root, $under, $type, $ignore, $package),
        );

        if (($result['unresolved'] ?? []) !== []) {
            throw new RuntimeException("Docs source [{$declared}]: files with no inferrable `type` (name it in the path, or give the source a `type`); nothing was imported: ".implode(', ', $result['unresolved']).'.');
        }
        if (($result['failed'] ?? []) !== []) {
            throw new RuntimeException("Docs source [{$declared}]: bodies that registered but would not compile: ".implode(', ', array_keys($result['failed'])).'.');
        }
    }

    /** The entry a source's top-level files hang from: a public path (`/docs`) or an entry id; null is the realm root. */
    private function under(?string $reference, string $declared): ?BeamUxEntry
    {
        if ($reference === null || $reference === '') {
            return null;
        }

        $entry = str_starts_with($reference, '/')
            ? (($chain = app(EntryPathResolver::class)->resolve($reference)) ? end($chain) : null)
            : BeamUxEntry::query()->whereKey($reference)->first();

        return $entry ?: throw new RuntimeException("Docs source [{$declared}]: no entry at under [{$reference}] to import beneath.");
    }
}
