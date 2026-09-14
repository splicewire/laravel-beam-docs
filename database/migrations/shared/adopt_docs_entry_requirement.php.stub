<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Splicewire\Beam\Ux\Models\BeamUxEntry;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('beam_ux_entries')) {
            return;
        }
        if (! Schema::hasColumn('beam_ux_entries', 'requirements')) {
            throw new RuntimeException('Install the Beam UX requirements migration before adopting docs.');
        }
        $root = BeamUxEntry::query()->where('slug', config('beam.docs.root_slug', 'docs'))
            ->where('namespace', config('beam.docs.root_namespace'))->first();
        if ($root !== null && ! in_array('beam-docs', $root->requirements ?? [], true)) {
            $root->requirements = [...($root->requirements ?? []), 'beam-docs'];
            $root->save();
        }
    }

    public function down(): void
    {
        // Retain the requirement: uninstalling documentation must not expose retained content.
    }
};
