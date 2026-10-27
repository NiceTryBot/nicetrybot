<?php

use Illuminate\Support\Facades\Route;
use NiceTry\Lockdown\Http\Controllers\LockdownController;

/*
| Signed-in pages, under the team: {current_team}/…
*/

Route::livewire('lockdown', 'lockdown::index')->name('lockdown.index');
Route::get('lockdown/preview', [LockdownController::class, 'preview'])->name('lockdown.preview');
Route::get('lockdown/pass/{domain}', [LockdownController::class, 'pass'])->name('lockdown.pass');
