<?php

use App\Console\Commands\ImportEdgeLogs;
use App\Models\GymSession;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::call(function () {
    GymSession::query()
        ->where('is_preview', true)
        ->where('started_at', '<', now()->subDay())
        ->delete();
})->daily()->description('Delete gym previews older than a day');

Schedule::command('visitor-data:prune')
    ->hourly()
    ->withoutOverlapping(30)
    ->environments('production')
    ->description('Delete visitor data past each team\'s retention');

Schedule::command('edge:import-logs')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->environments('production')
    ->when(ImportEdgeLogs::hasBucket(...))
    ->description('Import request logs shipped by the edge nodes');

Schedule::command('crawlers:refresh')
    ->dailyAt('03:40')
    ->timezone('UTC')
    ->environments('production')
    ->description('Download the IP ranges verified crawlers publish');

Schedule::command('clients:refresh-firefox')
    ->hourly()
    ->environments('production')
    ->description('Download the current and ESR Firefox versions that outdated Firefox is measured against');

Schedule::command('rules:evaluate')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->description('Run enabled rules over newly imported requests');

Schedule::command('addons:check-licences')
    ->daily()
    ->withoutOverlapping(30)
    ->description('Ask the marketplace whether each paid add-on\'s licence key is still good');
