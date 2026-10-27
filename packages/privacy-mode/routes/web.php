<?php

use Illuminate\Support\Facades\Route;
use NiceTry\PrivacyMode\Http\Controllers\ScriptController;

/*
| The words to hide, beside the core's settings.
*/

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    Route::livewire('settings/privacy', 'privacy-mode::settings')->name('privacy.edit');
});

/*
| The script, for every page with the app's head, signed in or not. It's the same for everyone, so it
| skips the web group: no session, no cookies.
*/

Route::get('privacy-mode.js', ScriptController::class)->name('privacy.script');
