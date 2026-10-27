<?php

use App\Http\Controllers\Gym\GymController;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

/*
| The visitor's side of the gym. The edge will send matching visitors here on the customer's own
| domain, so these routes deliberately skip the web group: no app session, CSRF or Livewire.
*/

Route::prefix(config('gym.path'))
    ->middleware(SubstituteBindings::class)
    ->group(function () {
        Route::get('demo/{obstacle}', [GymController::class, 'demo'])->middleware('signed')->name('gym.demo');
        Route::get('{gymSession}', [GymController::class, 'show'])->name('gym.show');
        Route::post('{gymSession}', [GymController::class, 'attempt'])->middleware('throttle:gym')->name('gym.attempt');
    });
