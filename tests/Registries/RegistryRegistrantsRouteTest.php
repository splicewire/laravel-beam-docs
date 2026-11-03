<?php

namespace Splicewire\Beam\Docs\Tests\Registries;

use Rushing\Popcorn\Registries\Authorizer;
use Rushing\Popcorn\Registries\BasicRegistry;
use Rushing\Popcorn\Registries\IsRegistry;
use Rushing\Popcorn\Registries\RegistryIndex;
use Rushing\Popcorn\Registries\RegistryKey;
use Splicewire\Beam\Docs\Registries\RegistryRegistrants;
use Splicewire\Beam\Docs\Tests\Fixtures\RegistryDocs\Beta\BetaRegistry;
use Splicewire\Beam\Docs\Tests\TestCase;

/**
 * The registrant panel `beam/docs/registries/{root}/registrants` (registry-docs ticket 03; ticket 01
 * amended ruling 6): live, host-variant, conditional on `RecordsRegistrants`. The owner and the
 * index's membership provenance are always answered; the per-entry list exists only where the
 * registry records registrants, and every read is filtered — a hidden entry is absent.
 */
class RegistryRegistrantsRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once dirname(__DIR__).'/Fixtures/RegistryDocs/packages/beta/src/BetaRegistry.php';
    }

    public function test_a_registry_recording_registrants_lists_its_visible_entries_and_who_wrote_them(): void
    {
        $store = new BasicRegistry(new IsRegistry(root: 'fixture.delta'));
        $store->register('first', 'one', by: 'vendor/first-package');
        $store->register('second', 'two', by: 'vendor/second-package');
        $store->register('anonymous', 'three');
        $store->register('hidden', 'four', by: 'vendor/secret-package', ability: 'see-hidden');
        // The host's ONE authorizer lives on the index, which pushes it into every described store.
        app(RegistryIndex::class)->describe($store, by: 'vendor/delta-owner')->authorizeWith(new class implements Authorizer
        {
            public function allows(string $ability, RegistryKey $key): bool
            {
                return false;
            }
        });

        $response = $this->get('beam/docs/registries/fixture.delta/registrants');

        $response->assertOk();
        $response->assertJson([
            'root' => 'fixture.delta',
            'describedHere' => true,
            'owner' => BasicRegistry::class,
            'describedBy' => 'vendor/delta-owner',
            'recordsRegistrants' => true,
            'unattributed' => 1,
            'note' => null,
        ]);
        $this->assertSame([
            ['key' => 'fixture.delta.first', 'registrant' => 'vendor/first-package'],
            ['key' => 'fixture.delta.second', 'registrant' => 'vendor/second-package'],
        ], $response->json('entries'));
        $this->assertStringNotContainsString('secret-package', $response->getContent(), 'a hidden entry is byte-identical to absent');
        $this->assertStringNotContainsString('hidden', (string) json_encode($response->json('entries')));
    }

    public function test_without_records_registrants_the_panel_keeps_owner_and_provenance_and_omits_the_list_with_one_line(): void
    {
        app(RegistryIndex::class)->describe(app(BetaRegistry::class), by: 'splicewire/fixture-beta');

        $response = $this->get('beam/docs/registries/fixture.beta/registrants');

        $response->assertOk();
        $response->assertJson([
            'root' => 'fixture.beta',
            'describedHere' => true,
            'owner' => BetaRegistry::class,
            'describedBy' => 'splicewire/fixture-beta',
            'recordsRegistrants' => false,
            'entries' => null,
            'unattributed' => null,
            'note' => RegistryRegistrants::NOT_RECORDED,
        ]);
    }

    public function test_a_root_this_host_does_not_describe_is_annotated_not_an_error(): void
    {
        $response = $this->get('beam/docs/registries/fixture.nowhere/registrants');

        $response->assertOk();
        $response->assertJson([
            'root' => 'fixture.nowhere',
            'describedHere' => false,
            'owner' => null,
            'describedBy' => null,
            'recordsRegistrants' => false,
            'entries' => null,
            'note' => 'not available on this host',
        ]);
    }
}
