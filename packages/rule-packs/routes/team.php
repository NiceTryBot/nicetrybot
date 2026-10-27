<?php

use Illuminate\Support\Facades\Route;

/*
| Signed-in pages, under the team: {current_team}/…
*/

Route::livewire('rules/packs', 'rule-packs::index')->name('rules.packs');
