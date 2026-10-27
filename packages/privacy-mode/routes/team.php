<?php

use Illuminate\Support\Facades\Route;
use NiceTry\PrivacyMode\Http\Controllers\HostnamesController;

/*
| Signed-in, under the team: {current_team}/…
*/

Route::get('privacy-hostnames.js', HostnamesController::class)->name('privacy.hostnames');
