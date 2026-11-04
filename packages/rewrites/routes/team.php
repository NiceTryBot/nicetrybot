<?php

use Illuminate\Support\Facades\Route;

/*
| Signed-in pages, under the team: {current_team}/…
*/

Route::livewire('rewrites', 'rewrites::index')->name('rewrites.index');
