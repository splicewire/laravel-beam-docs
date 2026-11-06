<?php

namespace Splicewire\Beam\Docs\Tests\Doctor;

use Illuminate\Validation\ValidationException;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Docs\Doctor\DocsDivergedAudit;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Mdx\Lens\MdxLens;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Codec\CodecRegistry;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;

/**
 * `docs.diverged` (docs-walkthrough DOCS-06, rule DOC-4, ADR-0215): a seeded or imported row whose
 * STORED body no longer equals the title+body hash its origin asserted (the host edited it). A WARN,
 * never a FAIL; a `cms` row is not judged; a pristine row is silent.
 */
class DocsDivergedAuditTest extends TestCase
{
    private DivergedMemoryDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        (require $ux.'/database/migrations/shared/add_provenance_to_beam_ux_entries_table.php.stub')->up();

        $this->driver = new DivergedMemoryDriver;
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $this->driver));
    }

    public function test_a_package_row_edited_since_its_origin_asserted_it_warns(): void
    {
        // Pristine: the stored body equals what the origin asserted.
        $this->entry('index', 'docs', 'Docs', "# Docs\nWelcome.", Provenance::package('splicewire/laravel-beam-docs'));
        // Edited: the asserted hash was taken over a DIFFERENT body than the one now stored.
        $this->entry('api', 'docs', 'API', "# API\nEdited by the host.", Provenance::package('splicewire/laravel-beam-docs'), assertedOver: "# API\nThe original stub.");

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Warn, $finding->status, $finding->detail);
        $this->assertStringContainsString('docs.api (package:splicewire/laravel-beam-docs)', $finding->detail);
        $this->assertStringNotContainsString('docs.index', $finding->detail);
        $this->assertStringContainsString('2 checked · 1 diverged', $finding->detail);
    }

    public function test_pristine_rows_pass(): void
    {
        $this->entry('index', 'docs', 'Docs', "# Docs\nWelcome.", Provenance::package('splicewire/laravel-beam-docs'));
        $this->entry('guide', 'docs', 'Guide', "# Guide\nSteps.", Provenance::disk('beam/docs/guide.mdx'));

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status, $finding->detail);
        $this->assertStringContainsString('2 checked · 0 diverged', $finding->detail);
    }

    public function test_a_cms_row_is_not_judged(): void
    {
        // A cms row's stored body differs from its (incidental) asserted hash, but it has no upstream
        // to diverge from, so the audit never considers it — leaving nothing to compare: inconclusive.
        $this->entry('page', 'docs', 'Page', "# Page\nAuthored here, then changed.", Provenance::CMS, assertedOver: "# Page\nFirst draft.");

        $finding = $this->finding();

        $this->assertFalse($finding->conclusive, $finding->detail);
    }

    // ── Unreadable and missing-origin rows are neither pristine nor diverged (registry-docs ticket 16) ────────────────

    public function test_a_row_this_host_cannot_print_is_counted_apart_and_never_passes(): void
    {
        // The flagship's shape (registry-docs ticket 14): MDX held as blocks, and a lens that does not resolve.
        config(['beam.ux.mdx.holds_blocks' => true]);
        $this->app->forgetInstance(CodecRegistry::class);
        $this->app->instance(MdxLens::class, new ThrowingDivergedLens);
        $this->entry('index', 'docs', 'Docs', "# Docs\nWelcome.", Provenance::package('splicewire/laravel-beam-docs'));
        $this->blocksEntry('build', 'docs', Provenance::disk('build.mdx'));
        $this->blocksEntry('using', 'docs', Provenance::disk('using.mdx'));

        $finding = $this->finding();

        $this->assertNotSame(DoctorStatus::Pass, $finding->status, $finding->detail);
        $this->assertFalse($finding->conclusive, 'two of three rows were never judged');
        $this->assertStringContainsString('1 checked · 0 diverged', $finding->detail);
        $this->assertStringContainsString('2 stored body unreadable on this host', $finding->detail);
        $this->assertStringContainsString('docs.build (disk:build.mdx)', $finding->detail);
        $this->assertStringContainsString('docs.using (disk:using.mdx)', $finding->detail);
        $this->assertStringContainsString(ThrowingDivergedLens::DIAGNOSTIC, $finding->detail, 'the first diagnostic is named');
    }

    public function test_a_disk_row_whose_origin_file_is_gone_is_counted_and_left_to_its_own_check(): void
    {
        $root = $this->sources(['guide.mdx']);
        $this->entry('guide', null, 'Guide', "# Guide\nSteps.", Provenance::disk('guide.mdx'));
        // www's `engines`: its file was deleted, and its stamp does not match (it was never re-stamped against a file).
        $this->entry('engines', null, 'Engines', "# Engines\nOld words.", Provenance::disk('engines.mdx'), assertedOver: "# Engines\nOlder.");

        $finding = $this->finding();

        // docs-feedback ticket 15: a missing origin is docs.origin-missing's WARN, not a sub-line of this check.
        $this->assertSame(DoctorStatus::Pass, $finding->status, $finding->detail);
        $this->assertStringContainsString('1 checked · 0 diverged', $finding->detail);
        $this->assertStringContainsString("\n1 origin missing, not judged here: see docs.origin-missing.", $finding->detail);
        $this->assertStringNotContainsString('engines', $finding->detail, 'never named as diverged');
        @unlink($root.'/guide.mdx');
        @rmdir($root);
    }

    public function test_only_a_missing_origin_is_still_conclusive(): void
    {
        $root = $this->sources(['guide.mdx']);
        $this->entry('engines', null, 'Engines', "# Engines\nOld words.", Provenance::disk('engines.mdx'));
        BeamUxEntry::query()->where('slug', 'engines')->update(['workflow_marking' => 'draft']);

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status, $finding->detail);
        $this->assertTrue($finding->conclusive);
        $this->assertStringContainsString('0 checked · 0 diverged', $finding->detail);
        $this->assertStringContainsString('1 origin missing, not judged here', $finding->detail);
        @unlink($root.'/guide.mdx');
        @rmdir($root);
    }

    /** Declare one docs source holding `$files`; returns its root. */
    private function sources(array $files): string
    {
        $root = sys_get_temp_dir().'/docs-diverged-'.uniqid();
        mkdir($root);
        foreach ($files as $file) {
            file_put_contents($root.'/'.$file, "# {$file}\n");
        }
        config(['beam.docs.sources' => [['path' => $root]]]);

        return $root;
    }

    /** A row whose stored body is blocks, so reading it needs the MDX lens's printer. */
    private function blocksEntry(string $slug, string $namespace, string $origin): void
    {
        $entry = BeamUxEntry::create(['slug' => $slug, 'namespace' => $namespace, 'type' => 'page', 'format' => 'mdx', 'title' => ucfirst($slug), 'origin' => $origin, 'asserted_hash' => str_repeat('a', 64)]);
        $item = $this->driver->write('', [['kind' => 'block', 'name' => 'p', 'isComponent' => false, 'props' => [], 'children' => [['kind' => 'text', 'value' => 'Words.']], 'dynamic' => false]], $namespace);
        $entry->forceFill(['particle_id' => $item->key])->save();
    }

    private function entry(string $slug, ?string $namespace, string $title, string $storedBody, string $origin, ?string $assertedOver = null): void
    {
        $entry = BeamUxEntry::create([
            'slug' => $slug,
            'namespace' => $namespace,
            'type' => 'page',
            'format' => 'mdx',
            'title' => $title,
            'origin' => $origin,
            // The hash the origin asserted — over the original body by default, or an earlier body to
            // simulate a host edit (stored != asserted).
            'asserted_hash' => Provenance::hash($title, $assertedOver ?? $storedBody),
        ]);
        $item = $this->driver->write('', $entry->codec()->encode($storedBody), $namespace);
        $entry->forceFill(['particle_id' => $item->key])->save();
    }

    private function finding(): Finding
    {
        $findings = array_values(array_filter(
            $this->app->make(DocsDivergedAudit::class)->run(),
            fn (Finding $f) => $f->check === 'docs.diverged',
        ));
        $this->assertCount(1, $findings);

        return $findings[0];
    }
}

/** An in-memory particle store. */
class DivergedMemoryDriver implements StorageDriver
{
    /** @var array<string, StorageItem> */
    private array $items = [];

    public function read(string $key): ?StorageItem
    {
        return $this->items[$key] ?? null;
    }

    public function write(string $key, array $body, ?string $namespace = null): StorageItem
    {
        $key = $key !== '' ? $key : 'p'.(count($this->items) + 1);

        return $this->items[$key] = new StorageItem($key, $body, $namespace, time());
    }

    public function list(?string $namespace = null): array
    {
        return array_values($this->items);
    }

    public function staleness(string $key, int $candidateModifiedAt): int
    {
        return 0;
    }
}

/** A host whose `@splicewire/beam-mdx` has no `./lens` export: the printer throws its own diagnostic. */
class ThrowingDivergedLens implements MdxLens
{
    public const DIAGNOSTIC = 'beam-mdx lens: cannot resolve [@splicewire/beam-mdx/lens] from the host at /srv/host ([@splicewire/beam-mdx] does not export [./lens])';

    public function read(string $body, array $context = []): array
    {
        throw ValidationException::withMessages(['source' => self::DIAGNOSTIC]);
    }

    public function print(array $doc): string
    {
        throw ValidationException::withMessages(['source' => self::DIAGNOSTIC]);
    }
}
