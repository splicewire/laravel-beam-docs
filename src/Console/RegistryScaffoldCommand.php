<?php

namespace Splicewire\Beam\Docs\Console;

use Illuminate\Console\Command;
use ReflectionClass;
use Rushing\Popcorn\Registries\IsRegistry;
use Splicewire\Beam\Docs\Registries\FamilyPackages;
use Splicewire\Beam\Docs\Registries\PackageDocsManifest;
use Splicewire\Beam\Docs\Registries\RegistryCensus;
use Splicewire\Beam\Docs\Registries\RegistryFactsBlock;

/**
 * Scaffold registry pages (registry-docs ticket 03; ticket 01 rulings 3, 6 and 9): for each declared
 * root a package owns, `docs/registries/<root>.mdx` — the dotted root VERBATIM as the filename, no
 * slugifier — carrying frontmatter, a generated-facts block and an empty authored section.
 *
 * **This command is the only writer of the facts block**, and it writes nothing else once a page
 * exists. Per root, exactly one of:
 *
 * - **created** — no page and no manifest entry: a fresh stub ({@see RegistryFactsBlock::stub()}).
 * - **refreshed** / **unchanged** — the page carries the facts markers: the block between them is
 *   re-rendered from the declaration; every byte outside the markers is preserved.
 * - **left alone** — the root is covered by the package's `manifest.json` (override, external or
 *   exemption), or its page carries no markers (wholly hand-authored). The command never writes it.
 *
 * `--panel` is the one-shot opt-in for pages written before the stub carried the registrant panel
 * ({@see RegistryFactsBlock::withPanel()}): after the pass above, each page of a selected root that has a
 * facts block and no `<RegistryRegistrants` tag gets one, on the line after the end marker. A second run
 * is a no-op. A page with no facts block (hand-authored) is never written; it is listed. A
 * manifest-covered root has no page to carry a panel. A cluster page spans several roots and has no
 * facts block, and it is not any one root's page, so the command never reaches it.
 *
 * Ownership is the census's ({@see RegistryCensus::owners()}): a page is only ever written into the
 * package that DECLARES the root (ruling 2). The empty root never enters — the census skips it.
 * Validation of the result (dead roots, wrong owners, drift) is the catalog command's and ticket 04's.
 */
class RegistryScaffoldCommand extends Command
{
    protected $signature = 'splicewire:beam:docs:registries:scaffold
        {package? : The vendor/name of one family package; omitted, every family package this process can see}
        {--root=* : Only these roots — exact, or a cluster as `prefix.*` (repeatable)}
        {--panel : Also place the <RegistryRegistrants> panel after the facts block of each existing page that lacks it}';

    protected $description = 'Scaffold or refresh docs/registries/<root>.mdx pages: frontmatter, the generated declaration facts, and an empty authored section.';

    public function handle(RegistryCensus $census, FamilyPackages $packages): int
    {
        $entries = $census->entries();
        $known = $packages->forCatalog();
        $only = $this->argument('package');

        if (is_string($only) && $only !== '' && ! isset($known[$only])) {
            $this->error("`{$only}` is not a family package this process can see (installed, root, or beam.docs.registries.packages).");

            return self::FAILURE;
        }

        $owners = $census->owners($entries, $known);
        $targets = is_string($only) && $only !== '' ? [$only => $known[$only]] : $known;
        $counts = ['created' => 0, 'refreshed' => 0, 'unchanged' => 0, 'manifest' => 0, 'authored' => 0];
        $panels = ['placed' => 0, 'present' => 0, 'skipped' => []];
        $panel = (bool) $this->option('panel');
        $failed = false;

        foreach ($targets as $name => $base) {
            $roots = array_values(array_filter(
                array_keys(array_filter($owners, fn (?string $owner) => $owner === $name)),
                fn (string $root) => $this->selected($root),
            ));
            sort($roots);

            if ($roots === []) {
                if ($only !== null) {
                    $this->warn("{$name} declares no registry roots in the census; nothing to scaffold.");
                }

                continue;
            }

            $dir = $base.'/docs/registries';
            $manifest = PackageDocsManifest::read($dir);

            if ($manifest->errors !== []) {
                foreach ($manifest->errors as $error) {
                    $this->error($error);
                }
                $this->error("{$name}: manifest is malformed; nothing written for this package.");
                $failed = true;

                continue;
            }

            foreach ($roots as $root) {
                $outcome = $this->scaffold($dir, $root, $entries[$root]['class'], $manifest);
                $counts[$outcome]++;
                $this->line(sprintf('  %-10s %s  docs/registries/%s.mdx', $outcome, $name, $root));

                if ($panel && $outcome !== 'manifest') {
                    $placed = $this->panel($dir.'/'.$root.'.mdx', $root);

                    if ($placed === null) {
                        $panels['skipped'][] = "{$name}  docs/registries/{$root}.mdx";
                    } else {
                        $panels[$placed ? 'placed' : 'present']++;

                        if ($placed) {
                            $this->line(sprintf('  %-10s %s  docs/registries/%s.mdx', 'panel', $name, $root));
                        }
                    }
                }
            }
        }

        $this->info(sprintf(
            '%d created, %d refreshed, %d unchanged; left alone: %d manifest-covered, %d hand-authored.',
            $counts['created'], $counts['refreshed'], $counts['unchanged'], $counts['manifest'], $counts['authored'],
        ));

        if ($panel) {
            $this->info(sprintf(
                'Panel: %d placed, %d already present; %d skipped (no facts block).',
                $panels['placed'], $panels['present'], count($panels['skipped']),
            ));

            foreach ($panels['skipped'] as $skipped) {
                $this->line("  no facts block, panel not placed: {$skipped}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** Place the panel on one page: true when written, false when it already carries one, null when it has no facts block. */
    private function panel(string $file, string $root): ?bool
    {
        $contents = (string) file_get_contents($file);
        $placed = RegistryFactsBlock::withPanel($contents, $root);

        if ($placed === null) {
            return null;
        }

        if ($placed === $contents) {
            return false;
        }

        file_put_contents($file, $placed);

        return true;
    }

    /** Whether `--root` selects this root: no filter selects all; `a.b.*` selects `a.b` and everything under it. */
    private function selected(string $root): bool
    {
        $filters = array_filter((array) $this->option('root'), fn ($f) => is_string($f) && $f !== '');

        if ($filters === []) {
            return true;
        }

        foreach ($filters as $filter) {
            if ($filter === $root) {
                return true;
            }

            if (str_ends_with($filter, '.*') && ($root === substr($filter, 0, -2) || str_starts_with($root, substr($filter, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    /** @param  class-string  $class */
    private function scaffold(string $dir, string $root, string $class, PackageDocsManifest $manifest): string
    {
        if (in_array($root, $manifest->roots(), true)) {
            return 'manifest';
        }

        // Read off the declaring class itself — the census found the attribute there.
        $declaration = (new ReflectionClass($class))->getAttributes(IsRegistry::class)[0]->newInstance();
        $file = $dir.'/'.$root.'.mdx';

        if (! is_file($file)) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($file, RegistryFactsBlock::stub($declaration, $class));

            return 'created';
        }

        $contents = (string) file_get_contents($file);
        $refreshed = RegistryFactsBlock::replaceIn($contents, RegistryFactsBlock::render($declaration, $class));

        if ($refreshed === null) {
            return 'authored';
        }

        if ($refreshed === $contents) {
            return 'unchanged';
        }

        file_put_contents($file, $refreshed);

        return 'refreshed';
    }
}
