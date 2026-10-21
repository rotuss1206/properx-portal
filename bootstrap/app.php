<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\DisableRememberMe;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::group([], base_path('routes/health.php'));
            Route::middleware('throttle:120,1')->group(base_path('routes/machine.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);

        $middleware->alias(['auth.api-key' => AuthenticateApiKey::class]);

        $middleware->web(append: [
            DisableRememberMe::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The default message embeds the query bindings (emails, NPNs, ...). Log the SQL with
        // placeholders only, so no member data reaches the logs.
        $exceptions->report(function (QueryException $e) {
            Log::error('Database query failed', [
                'exception' => $e::class,
                'code' => $e->getCode(),
                'connection' => $e->getConnectionName(),
                'sql' => $e->getSql(),
            ]);

            return false;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
