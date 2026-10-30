<?php

use App\Http\Middleware\AddServerTiming;
use App\Http\Middleware\SetTeamUrlDefaults;
use App\Http\Middleware\TrustEdgeForwardedHost;
use App\Http\Middleware\UseVaporSourceIp;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], __DIR__.'/../routes/edge.php');
            Route::group([], __DIR__.'/../routes/gym.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend([AddServerTiming::class, UseVaporSourceIp::class]);

        // Proxies may say who the visitor is and how they arrived, but not which host they asked for:
        // only the edge may, with its token (TrustEdgeForwardedHost).
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_AWS_ELB);
        $middleware->append(TrustEdgeForwardedHost::class);

        $middleware->web(append: [
            SetTeamUrlDefaults::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
