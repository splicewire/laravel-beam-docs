<?php

namespace Splicewire\Beam\Docs\Tests\Reconcile;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rushing\Doctor\DoctorStatus;
use Rushing\Doctor\Finding;
use Splicewire\Beam\Docs\Doctor\DocsDivergedAudit;
use Splicewire\Beam\Docs\Doctor\DocsOriginMissingAudit;
use Splicewire\Beam\Docs\Reconcile\DocsReconciler;
use Splicewire\Beam\Docs\Reconcile\ReconcileItem;
use Splicewire\Beam\Docs\Reconcile\ReleaseTakeDown;
use Splicewire\Beam\Docs\Seed\DocsSourcesSeeder;
use Splicewire\Beam\Docs\Tests\TestCase;
use Splicewire\Beam\Storage\StorageDriver;
use Splicewire\Beam\Storage\StorageItem;
use Splicewire\Beam\Ux\Models\BeamUxEntry;
use Splicewire\Beam\Ux\Provenance\Provenance;
use Splicewire\Beam\Ux\Storage\StorageDriverResolver;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

/**
 * A moved or deleted docs source takes its page down, with a redirect on a move (docs-feedback ticket 15).
 *
 * Which rows are vestigial is decided from provenance; what happened to each file comes from git (a committed or an
 * uncommitted rename or delete) or, for a registry page, from the registry-root mapping. Anything else is reported with
 * its candidate and never acted on. Applying goes through the Release take-down seam only.
 */
class DocsReconcileTest extends TestCase
{
    private string $dir;

    private ReconcileMemoryDriver $driver;

    /** @var list<array{entry: string, target: ?string, title: string}> */
    private array $takenDown = [];

    protected function setUp(): void
    {
        parent::setUp();

        $ux = dirname((new \ReflectionClass(BeamUxEntry::class))->getFileName(), 3);
        (require $ux.'/database/migrations/shared/create_beam_ux_entries_table.php.stub')->up();
        $this->driver = new ReconcileMemoryDriver;
        $this->app->instance(StorageDriverResolver::class, (new StorageDriverResolver)->register(StorageDriverResolver::DEFAULT, $this->driver));

        $this->dir = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()).'/docs-reconcile-'.bin2hex(random_bytes(4));
        mkdir($this->dir);

        $test = $this;
        $this->app->instance(ReleaseTakeDown::class, new class($test) extends ReleaseTakeDown
        {
            public function __construct(private DocsReconcileTest $test) {}

            public function takeDown(BeamUxEntry $entry, ?BeamUxEntry $target, string $title, Authenticatable $actor): array
            {
                $this->test->recordTakeDown($entry, $target, $title);

                return ['release-'.$entry->slug, 'published'];
            }
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function recordTakeDown(BeamUxEntry $entry, ?BeamUxEntry $target, string $title): void
    {
        $this->takenDown[] = ['entry' => (string) $entry->slug, 'target' => $target?->slug, 'title' => $title];
    }

    // ── Rename ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_committed_rename_redirects_the_old_row_to_the_new_one(): void
    {
        $this->hostRepo(['docs/page/layers.mdx' => "# Layers\nThe layers of Beam.\n"]);
        $this->git('mv', 'docs/page/layers.mdx', 'docs/page/layering.mdx');
        $this->git('commit', '-qm', 'rename');
        $this->row('layers', 'docs', Provenance::disk('docs/page/layers.mdx'));
        $this->row('layering', 'docs', Provenance::disk('docs/page/layering.mdx'));

        [$item] = $this->plan();

        $this->assertSame(DocsReconciler::REDIRECT, $item->action, $item->describe());
        $this->assertSame('layering', $item->target?->slug);
        $this->assertStringContainsString('renamed to', $item->evidence);
        $this->assertStringNotContainsString('work tree', $item->evidence, 'the rename was read from the commit');
    }

    public function test_an_uncommitted_rename_redirects_too(): void
    {
        $this->hostRepo(['docs/page/layers.mdx' => "# Layers\nThe layers of Beam.\n"]);
        $this->git('mv', 'docs/page/layers.mdx', 'docs/page/layering.mdx');
        $this->row('layers', 'docs', Provenance::disk('docs/page/layers.mdx'));
        $this->row('layering', 'docs', Provenance::disk('docs/page/layering.mdx'));

        [$item] = $this->plan();

        $this->assertSame(DocsReconciler::REDIRECT, $item->action, $item->describe());
        $this->assertStringContainsString('in the work tree', $item->evidence);
    }

    public function test_a_renamed_registry_root_maps_to_its_new_page_without_git(): void
    {
        // Ticket 05's shape: a package's registry page renamed from the snake to the kebab spelling of its root.
        $pages = $this->dir.'/laravel-beam-notifications/docs/registries';
        mkdir($pages, 0777, true);
        file_put_contents($pages.'/beam.notifications.recipient-kinds.mdx', "# Recipient kinds\n");
        config(['beam.docs.sources' => [['path' => $pages, 'type' => 'page', 'package' => 'splicewire/laravel-beam-notifications']]]);
        $origin = Provenance::package('splicewire/laravel-beam-notifications');
        $this->row('beam.notifications.recipient_kinds', null, $origin);
        $this->row('beam.notifications.recipient-kinds', null, $origin);

        [$item] = $this->plan();

        $this->assertSame(DocsReconciler::REDIRECT, $item->action, $item->describe());
        $this->assertSame('beam.notifications.recipient-kinds', $item->target?->slug);
        $this->assertStringContainsString('registry-root mapping', $item->evidence);
    }

    // ── Delete ──────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_deleted_source_takes_its_page_down_without_a_redirect(): void
    {
        $this->hostRepo(['docs/page/engines.mdx' => "# Engines\n", 'docs/page/using.mdx' => "# Using\n"]);
        $this->git('rm', '-q', 'docs/page/engines.mdx');
        $this->git('commit', '-qm', 'delete');
        $this->row('engines', 'docs', Provenance::disk('docs/page/engines.mdx'));
        $this->row('using', 'docs', Provenance::disk('docs/page/using.mdx'));

        [$item] = $this->plan();

        $this->assertSame('engines', $item->row->entry->slug);
        $this->assertSame(DocsReconciler::TAKEDOWN, $item->action, $item->describe());
        $this->assertNull($item->target);
        $this->assertStringContainsString('deleted in', $item->evidence);
    }

    public function test_a_package_page_deleted_from_its_package_is_taken_down(): void
    {
        $pages = $this->dir.'/pkg/docs/registries';
        mkdir($pages, 0777, true);
        file_put_contents($pages.'/beam.engines.mdx', "# Engines\n");
        file_put_contents($pages.'/beam.kept.mdx', "# Kept\n");
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('commit', '-qm', 'pages');
        $this->git('rm', '-q', 'pkg/docs/registries/beam.engines.mdx');
        config(['beam.docs.sources' => [['path' => $pages, 'type' => 'page', 'package' => 'vendor/pkg']]]);
        $this->row('beam.engines', null, Provenance::package('vendor/pkg'));
        $this->row('beam.kept', null, Provenance::package('vendor/pkg'));
        // Another package's row is never judged against this package's files.
        $this->row('beam.elsewhere', null, Provenance::package('vendor/other'));

        $plan = $this->plan();

        $this->assertCount(1, $plan);
        $this->assertSame('beam.engines', $plan[0]->row->entry->slug);
        $this->assertSame(DocsReconciler::TAKEDOWN, $plan[0]->action, $plan[0]->describe());
    }

    // ── Ambiguous ───────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_no_recoverable_history_is_reported_never_acted_on(): void
    {
        mkdir($this->dir.'/docs/page', 0777, true);
        file_put_contents($this->dir.'/docs/page/using.mdx', "# Using\n");
        config(['beam.docs.sources' => [['path' => $this->dir, 'type' => 'page']]]);
        $this->row('engines', 'docs', Provenance::disk('docs/page/engines.mdx'));

        [$item] = $this->plan();

        $this->assertSame(DocsReconciler::REPORT, $item->action);
        $this->assertStringContainsString('ambiguous: no recoverable history for '.$this->dir.'/docs/page/engines.mdx', $item->evidence);
    }

    public function test_a_rename_whose_target_has_no_row_is_reported_naming_the_candidate(): void
    {
        $this->hostRepo(['docs/page/layers.mdx' => "# Layers\nThe layers of Beam.\n"]);
        $this->git('mv', 'docs/page/layers.mdx', 'docs/page/layering.mdx');
        $this->row('layers', 'docs', Provenance::disk('docs/page/layers.mdx'));

        [$item] = $this->plan();

        $this->assertSame(DocsReconciler::REPORT, $item->action);
        $this->assertStringContainsString('it has no row yet (seed first)', $item->evidence);
        $this->assertStringContainsString('(candidate: '.$this->dir.'/docs/page/layering.mdx)', $item->describe());
    }

    public function test_several_respellings_of_one_registry_root_are_reported_with_every_candidate(): void
    {
        $pages = $this->dir.'/pkg/docs/registries';
        mkdir($pages, 0777, true);
        file_put_contents($pages.'/beam.recipient-kinds.mdx', "# A\n");
        file_put_contents($pages.'/beam.Recipient_kinds.mdx', "# B\n");
        config(['beam.docs.sources' => [['path' => $pages, 'type' => 'page', 'package' => 'vendor/pkg']]]);
        $this->row('beam.recipient_kinds', null, Provenance::package('vendor/pkg'));
        $this->row('beam.recipient-kinds', null, Provenance::package('vendor/pkg'));
        $this->row('beam.Recipient_kinds', null, Provenance::package('vendor/pkg'));

        [$item] = $this->plan();

        $this->assertSame(DocsReconciler::REPORT, $item->action);
        $this->assertStringContainsString('several respellings', $item->evidence);
        $this->assertCount(2, $item->candidates);
    }

    public function test_git_and_the_registry_mapping_disagreeing_is_reported(): void
    {
        $pages = $this->dir.'/pkg/docs/registries';
        mkdir($pages, 0777, true);
        file_put_contents($pages.'/beam.recipient_kinds.mdx', "# Recipient kinds\nWho a notification reaches.\n");
        file_put_contents($pages.'/beam.recipient-kinds.mdx', "# Something else\n");
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('commit', '-qm', 'pages');
        $this->git('mv', 'pkg/docs/registries/beam.recipient_kinds.mdx', 'pkg/docs/registries/beam.audiences.mdx');
        config(['beam.docs.sources' => [['path' => $pages, 'type' => 'page', 'package' => 'vendor/pkg']]]);
        foreach (['beam.recipient_kinds', 'beam.recipient-kinds', 'beam.audiences'] as $slug) {
            $this->row($slug, null, Provenance::package('vendor/pkg'));
        }

        [$item] = $this->plan();

        $this->assertSame(DocsReconciler::REPORT, $item->action, $item->describe());
        $this->assertStringContainsString('ambiguous: git:', $item->evidence);
        $this->assertCount(2, $item->candidates);
    }

    // ── Already down, apply, command, doctor ────────────────────────────────────────────────────────────────────────

    public function test_an_unpublished_vestigial_row_is_already_down(): void
    {
        $this->hostRepo(['docs/page/using.mdx' => "# Using\n"]);
        $this->row('engines', 'docs', Provenance::disk('docs/page/engines.mdx'), marking: 'draft');

        [$item] = $this->plan();

        $this->assertSame(DocsReconciler::DOWN, $item->action);
    }

    public function test_apply_takes_down_redirects_and_deletes_only_through_the_release_seam(): void
    {
        $this->mixedHost();

        $plan = app(DocsReconciler::class)->plan();
        $outcomes = app(DocsReconciler::class)->apply($plan, new User);

        $this->assertSame([
            ['entry' => 'engines', 'target' => null],
            ['entry' => 'layers', 'target' => 'layering'],
        ], array_map(fn ($t) => ['entry' => $t['entry'], 'target' => $t['target']], $this->takenDown));
        $this->assertCount(2, $outcomes);
        $this->assertSame(4, BeamUxEntry::query()->count(), 'no row is ever deleted');
    }

    public function test_the_command_is_a_dry_run_until_apply_and_apply_needs_an_actor_and_leaves_receipts(): void
    {
        $this->mixedHost();
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        DB::table('users')->insert(['id' => 7, 'name' => 'Author', 'email' => 'author@example.test']);
        config(['auth.providers.users.model' => User::class]);
        $receipts = $this->dir.'/receipts.jsonl';

        $this->artisan('splicewire:beam:docs:reconcile')
            ->expectsOutputToContain('3 vestigial · 1 redirect · 1 takedown · 1 report · 0 already down.')
            ->expectsOutputToContain('Dry run: nothing written.')
            ->assertSuccessful();
        $this->assertSame([], $this->takenDown, 'a dry run takes nothing down');

        $this->artisan('splicewire:beam:docs:reconcile', ['--apply' => true])->assertFailed();
        $this->assertSame([], $this->takenDown, 'no actor, no Release');

        $this->artisan('splicewire:beam:docs:reconcile', ['--apply' => true, '--actor' => 'author@example.test', '--receipts' => $receipts])->assertSuccessful();
        $lines = array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($receipts))));
        $this->assertSame(['engines' => 'takedown', 'layers' => 'redirect'], array_column($lines, 'action', 'slug'));
        $this->assertSame(['release-engines', 'release-layers'], array_column($lines, 'release'));
        $this->assertSame(['7'], array_values(array_unique(array_column($lines, 'actor'))));
    }

    public function test_the_doctor_warns_on_a_published_vestigial_row_and_passes_once_it_is_down(): void
    {
        $this->mixedHost();

        $finding = $this->finding();
        $this->assertSame(DoctorStatus::Warn, $finding->status);
        $this->assertStringContainsString('3 published · 0 taken down', $finding->detail);
        $this->assertStringContainsString('docs.layers (disk:docs/page/layers.mdx, published): redirect → docs.layering', $finding->detail);
        $this->assertStringContainsString('docs.engines (disk:docs/page/engines.mdx, published): takedown.', $finding->detail);
        $this->assertStringContainsString('docs.orphan (disk:docs/page/orphan.mdx, published): report.', $finding->detail);

        $diverged = $this->app->make(DocsDivergedAudit::class)->run()[0];
        $this->assertStringContainsString('3 origin missing, not judged here: see docs.origin-missing.', $diverged->detail);
        $this->assertSame(DoctorStatus::Pass, $diverged->status, 'docs.diverged no longer carries the missing origins');

        BeamUxEntry::query()->whereIn('slug', ['layers', 'engines', 'orphan'])->update(['workflow_marking' => 'draft']);
        $finding = $this->finding();
        $this->assertSame(DoctorStatus::Pass, $finding->status, $finding->detail);
        $this->assertStringContainsString('0 published · 3 taken down', $finding->detail);
    }

    public function test_the_seed_prints_the_plan_as_a_dry_run_and_takes_nothing_down(): void
    {
        $this->mixedHost();
        $output = new BufferedOutput;
        $command = new Command;
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));
        $seeder = (new DocsSourcesSeeder)->setCommand($command);

        (new \ReflectionMethod($seeder, 'reconcilePlan'))->invoke($seeder);

        $printed = $output->fetch();
        $this->assertStringContainsString('3 published row(s) whose source file moved or went away (dry run; nothing taken down)', $printed);
        $this->assertStringContainsString('docs.layers (disk:docs/page/layers.mdx, published): redirect → docs.layering', $printed);
        $this->assertStringContainsString('splicewire:beam:docs:reconcile --apply --actor=', $printed);
        $this->assertSame([], $this->takenDown);
    }

    public function test_the_doctor_passes_when_every_origin_is_present(): void
    {
        $this->hostRepo(['docs/page/using.mdx' => "# Using\n"]);
        $this->row('using', 'docs', Provenance::disk('docs/page/using.mdx'));

        $finding = $this->finding();

        $this->assertSame(DoctorStatus::Pass, $finding->status);
        $this->assertTrue($finding->conclusive);
        $this->assertStringContainsString('0 published · 0 taken down', $finding->detail);
    }

    // ── Fixtures ────────────────────────────────────────────────────────────────────────────────────────────────────

    /** A host with one committed rename, one uncommitted delete, one row with no history, and one live row. */
    private function mixedHost(): void
    {
        $this->hostRepo(['docs/page/layers.mdx' => "# Layers\nThe layers of Beam.\n", 'docs/page/engines.mdx' => "# Engines\n"]);
        $this->git('mv', 'docs/page/layers.mdx', 'docs/page/layering.mdx');
        $this->git('commit', '-qm', 'rename');
        $this->git('rm', '-q', 'docs/page/engines.mdx');
        $this->row('layers', 'docs', Provenance::disk('docs/page/layers.mdx'));
        $this->row('layering', 'docs', Provenance::disk('docs/page/layering.mdx'));
        $this->row('engines', 'docs', Provenance::disk('docs/page/engines.mdx'));
        $this->row('orphan', 'docs', Provenance::disk('docs/page/orphan.mdx'));
    }

    /** A git work tree at the temp dir holding `$files`, committed, declared as the host's one docs source. */
    private function hostRepo(array $files): void
    {
        foreach ($files as $path => $body) {
            @mkdir(dirname($this->dir.'/'.$path), 0777, true);
            file_put_contents($this->dir.'/'.$path, $body);
        }
        $this->git('init', '-q');
        $this->git('add', '.');
        $this->git('commit', '-qm', 'docs');
        config(['beam.docs.sources' => [['path' => $this->dir, 'type' => 'page']]]);
    }

    private function git(string ...$args): void
    {
        $process = new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false', ...$args], $this->dir);
        $process->mustRun();
    }

    private function row(string $slug, ?string $namespace, string $origin, string $marking = 'published'): BeamUxEntry
    {
        $entry = BeamUxEntry::create(['slug' => $slug, 'namespace' => $namespace, 'type' => 'page', 'format' => 'mdx', 'title' => ucfirst($slug), 'origin' => $origin]);
        $item = $this->driver->write('', $entry->codec()->encode("# {$slug}\n"), $namespace);
        $entry->forceFill(['particle_id' => $item->key, 'asserted_hash' => Provenance::hash($entry->title, "# {$slug}\n"), 'workflow_marking' => $marking])->save();

        return $entry;
    }

    /** @return list<ReconcileItem> */
    private function plan(): array
    {
        return app(DocsReconciler::class)->plan();
    }

    private function finding(): Finding
    {
        $findings = $this->app->make(DocsOriginMissingAudit::class)->run();
        $this->assertCount(1, $findings);

        return $findings[0];
    }
}

/** An in-memory particle store. */
class ReconcileMemoryDriver implements StorageDriver
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
