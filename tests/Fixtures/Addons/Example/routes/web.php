<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->get('settings/example', fn () => 'Example settings')->name('example.settings');
