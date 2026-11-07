<?php

use App\Exceptions\Renderers\SchedulingExceptionRenderer;
use App\Http\Middleware\AuthTokenStoreScopeMiddleware;
use App\Http\Middleware\CheckSecretKeyMiddleware;
use App\Http\Middleware\ObservableThrottleRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.token.store' => AuthTokenStoreScopeMiddleware::class,
            'auth.secret.key' => CheckSecretKeyMiddleware::class,
            'throttle' => ObservableThrottleRequests::class,
        ]);

        // The proxies themselves come from config/trustedproxy.php (TRUSTED_PROXIES).
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Domain failures carry an error code the UI branches on; without this
        // they'd surface as an opaque 500.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return app(SchedulingExceptionRenderer::class)->render($e);
        });
    })->create();
