<?php

use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only trust the local reverse proxy when it is used for development.
        // Direct local access on port 8080 remains the canonical workflow.
        $middleware->trustProxies(['127.0.0.1', '::1']);

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['smtp_password']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            if ($request->isMethod('post') && $request->is('training/weeks/*/video-recording')) {
                return redirect()->back()->withErrors([
                    'video' => 'The video must not exceed 350 MB.',
                ]);
            }

            return null;
        });
    })->create();
