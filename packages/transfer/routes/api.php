<?php

use App\Http\Middleware\AuthenticateApiToken;
use Illuminate\Support\Facades\Route;
use NiceTry\Transfer\Http\Controllers\TransferApi;

/*
| What another panel pulls an origin with, using an API token made in Admin → API tokens with "Add and
| change domains" (a bundle holds the origin's secret). Without a working token the token check answers
| exactly as a path that was never routed does, and so does everything below for a token that may not
| have what it asks for, so nothing here says an API exists. No session, cookies or CSRF: the token is
| the whole of who's asking.
*/

Route::middleware([AuthenticateApiToken::class, 'throttle:60,1'])->prefix('transfer/v1')->group(function () {
    Route::get('origins', [TransferApi::class, 'index'])->name('transfer.api.origins');
    Route::get('origins/{origin}', [TransferApi::class, 'show'])->whereNumber('origin')->name('transfer.api.origin');
});
