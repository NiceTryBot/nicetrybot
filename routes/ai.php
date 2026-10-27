<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Mcp\Servers\NiceTryBotServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
 * The MCP server, for an AI agent with an API token an administrator made in Admin. Every method on
 * the path goes through the token check first, so without a working token it's the same 404 any
 * made-up path gets: nothing here says an API exists. (The package adds GET and DELETE answers; the
 * other methods are listed so the router doesn't answer them with a 405 or an OPTIONS Allow list.)
 */
Route::middleware(AuthenticateApiToken::class)->group(function () {
    Mcp::web('mcp', NiceTryBotServer::class)->name('mcp');

    Route::match(['PUT', 'PATCH', 'OPTIONS'], 'mcp', fn () => abort(404));
});
