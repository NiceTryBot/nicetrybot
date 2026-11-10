<?php

use Illuminate\Support\Facades\Route;

/*
| Allowing an address reaches every site on the edge, so the list belongs to the installation's
| administrators. This page answers 404 while the add-on is off.
*/

Route::middleware(['web', 'auth', 'verified', 'can:administer'])->group(function () {
    Route::livewire('admin/white-list', 'white-list::index')->name('admin.white-list');
});
