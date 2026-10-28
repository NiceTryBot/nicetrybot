<?php

use Illuminate\Support\Facades\Route;

/*
| Signed-in pages, under the team: {current_team}/…
*/

Route::livewire('tarpits', 'tarpit::index')->name('tarpits.index');
