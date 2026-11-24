<?php

use App\Http\Middleware\UseAdminSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Public pages skip the "web" group on purpose: no session, no CSRF
            // token, so no cookies. The public app has no accounts or forms.
            Route::middleware('public')->group(base_path('routes/web.php'));

            // The admin only exists when ADMIN_PATH is set.
            if (($path = config('admin.path')) !== '') {
                Route::middleware('admin')->prefix($path)->group(base_path('routes/admin.php'));
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The web stack, with the admin's own session cookie swapped in first.
        $middleware->group('public', [SubstituteBindings::class]);
        $middleware->group('admin', [UseAdminSession::class, 'web']);
        $middleware->prependToPriorityList(before: StartSession::class, prepend: UseAdminSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
