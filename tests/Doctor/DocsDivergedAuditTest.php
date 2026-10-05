<?php

namespace Splicewire\Beam\Docs\Tests\Doctor;

use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Docs\Doctor\DocsDivergedAudit;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
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

    private function entry(string $slug, string $namespace, string $title, string $storedBody, string $origin, ?string $assertedOver = null): void
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
