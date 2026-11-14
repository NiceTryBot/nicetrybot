<?php

use Illuminate\Support\Facades\Route;

/*
| Signed-in pages, under the team: {current_team}/…
*/

Route::livewire('swarm-mode', 'swarm-mode::index')->name('swarm-mode.index');
