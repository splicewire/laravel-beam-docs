<?php

namespace Splicewire\Beam\Docs\Tests\Publishing;

class ScalarCliProtocolTest extends PublicationTestCase
{
    public function test_the_pinned_cli_colored_table_accepts_matching_access_and_refuses_a_mismatch(): void
    {
        // Captured from console-table-printer 2.16.1 using @scalar/cli 2.1.0's exact
        // columns and NO_COLOR=1. The CLI still colors table headers and cells.
        // Source: https://registry.npmjs.org/@scalar/cli/-/cli-2.1.0.tgz,
        // package/chunks/action-F4Y6Q2VB.js and package/chunks/chunk-X4QLDBB5.js.
        $listing = implode("\n", json_decode(
            file_get_contents(__DIR__.'/fixtures/registry-list-2.1.0.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        ))."\n";
        $this->assertStringContainsString("\e[", $listing);
        config(['beam.docs.visibility' => 'private']);
        $this->fake(['listing' => $listing]);

        $result = $this->service()->run($this->service()->capture('colored-1')->id);

        $this->assertSame('succeeded', $result->status, $result->error ?? '');
        $this->fake(['listing' => str_replace('Private', 'Public ', $listing)]);

        $refused = $this->service()->run($this->service()->capture('colored-2')->id);

        $this->assertSame('failed', $refused->status);
        $this->assertStringContainsString('visibility does not match', $refused->error);
        $uploads = array_filter($this->calls(), fn ($call) => array_slice($call['args'], 0, 2) === ['registry', 'publish']);
        $this->assertCount(1, $uploads, 'Only the publication whose access matched may upload.');
    }
}
