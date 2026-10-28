<?php

use App\Http\Controllers\Edge\AuthorizeCertificateController;
use App\Http\Controllers\Edge\GymCheckController;
use App\Http\Controllers\Edge\GymReportController;
use App\Http\Controllers\Edge\IngestEdgeLogsController;
use App\Http\Controllers\Edge\ListGymTriggersController;
use App\Http\Controllers\Edge\ListLiveTailsController;
use App\Http\Controllers\Edge\ListLockdownsController;
use App\Http\Controllers\Edge\ListOriginsController;
use App\Http\Controllers\Edge\ListResponseCapturesController;
use App\Http\Controllers\Edge\ListSiteHeadersController;
use App\Http\Controllers\Edge\ListTarpitsController;
use App\Http\Controllers\Edge\ResolveOriginController;
use App\Http\Middleware\AuthenticateEdge;
use Illuminate\Support\Facades\Route;

Route::get('caddy/ask', AuthorizeCertificateController::class)->name('edge.certificates.authorize');
Route::middleware(AuthenticateEdge::class)->group(function () {
    Route::get('caddy/response-captures', ListResponseCapturesController::class)->middleware('cache.headers:no_cache;etag')->name('edge.captures.index');
    Route::get('caddy/gym-triggers', ListGymTriggersController::class)->middleware('cache.headers:no_cache;etag')->name('edge.gym.triggers');
    Route::get('caddy/gym-check', GymCheckController::class)->name('edge.gym.check');
    Route::get('caddy/gym-report', GymReportController::class)->name('edge.gym.report');
    Route::get('caddy/origins', ListOriginsController::class)->middleware('cache.headers:no_cache;etag')->name('edge.origins');
    Route::get('caddy/resolve-origin', ResolveOriginController::class)->name('edge.origins.resolve');
    Route::get('caddy/site-headers', ListSiteHeadersController::class)->name('edge.site-headers');
    Route::get('caddy/live-tail', ListLiveTailsController::class)->name('edge.live-tail');
    Route::get('caddy/lockdowns', ListLockdownsController::class)->middleware('cache.headers:no_cache;etag')->name('edge.lockdowns');
    Route::get('caddy/tarpit-feed', ListTarpitsController::class)->middleware('cache.headers:no_cache;etag')->name('edge.tarpits');
    Route::post('caddy/ingest', IngestEdgeLogsController::class)->name('edge.ingest');
});
