<?php

use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

        Route::livewire('domains', 'pages::domains.index')->name('domains.index');
        Route::livewire('domains/{domain}', 'pages::domains.show')->name('domains.show');

        Route::livewire('activity', 'pages::activity.index')->name('activity.index');
        Route::livewire('analytics', 'pages::analytics.index')->name('analytics.index');
        Route::livewire('analytics/flows', 'pages::analytics.flows')->name('analytics.flows');
        Route::livewire('analytics/stats', 'pages::analytics.stats')->name('analytics.stats');
        Route::livewire('analytics/map', 'pages::analytics.map')->name('analytics.map');
        Route::livewire('not-found', 'pages::not-found.index')->name('not-found.index');
        Route::livewire('rules', 'pages::rules.index')->name('rules.index');
        Route::livewire('tags', 'pages::tags.index')->name('tags.index');

        Route::livewire('gym', 'pages::gym.index')->name('gym.index');
        Route::livewire('gym/routines/new', 'pages::gym.edit')->name('gym.routines.create');
        Route::livewire('gym/routines/{routine}', 'pages::gym.edit')->name('gym.routines.edit');

        Route::livewire('visitor-data', 'pages::visitor-data.index')->name('visitor-data.index');
    });

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
