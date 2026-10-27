<?php

use Illuminate\Support\Facades\Route;

/*
 * The installation's administration, for administrators only (php artisan admin:grant makes the first):
 * what's installation-wide rather than a team's.
 */
Route::middleware(['auth', 'verified', 'can:administer'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/addons')->name('index');

    Route::livewire('addons', 'pages::admin.addons')->name('addons');
    Route::livewire('administrators', 'pages::admin.administrators')->name('administrators');
    Route::livewire('tokens', 'pages::admin.tokens')->name('tokens');
});
