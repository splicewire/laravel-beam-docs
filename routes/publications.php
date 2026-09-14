<?php

use Illuminate\Support\Facades\Route;
use Splicewire\Beam\Docs\Publishing\Http\EnsurePublishingHost;
use Splicewire\Beam\Docs\Publishing\Http\PublicationController;

Route::middleware(['web', EnsurePublishingHost::class])->prefix('beam/docs/publications')->name('beam.docs.publications.')->group(function (): void {
    Route::get('/', [PublicationController::class, 'index'])->name('index');
    Route::post('/', [PublicationController::class, 'store'])->name('store');
    Route::get('/{publication}', [PublicationController::class, 'show'])->whereUuid('publication')->name('show');
    Route::post('/{publication}/retry', [PublicationController::class, 'retry'])->whereUuid('publication')->name('retry');
});
