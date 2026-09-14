<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rushing\SchemaConvergence\ConvergentTable;
use Splicewire\Beam\Facades\Beam;

// Host-only delivery bookkeeping: install at the host migration root, never under tenant/shared.
return new class extends Migration
{
    public function up(): void
    {
        ConvergentTable::named(Beam::table('docs_publications'))->define(function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('version', 128);
            $table->string('namespace');
            $table->string('slug');
            $table->boolean('is_private');
            $table->longText('snapshot');
            $table->string('sha256', 64);
            $table->string('status', 16)->index();
            $table->uuid('retry_of')->nullable()->unique();
            $table->text('registry_url')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        })->assert();
    }

    public function down(): void
    {
        Schema::dropIfExists(Beam::table('docs_publications'));
    }
};
