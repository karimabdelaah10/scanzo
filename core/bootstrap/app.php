<?php

use App\Exceptions\DomainException;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureBusinessIsWritable;
use App\Http\Middleware\SetBusinessContext;
use App\Http\Middleware\UseAdminSession;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The till is a same-origin PWA, so the API authenticates with the
        // session cookie instead of a token package. That means the api group
        // needs the cookie, session and CSRF stack the web group has — the
        // CSRF middleware is what keeps cookie auth from being forgeable, so
        // it is not optional here.
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);

        // Tenant resolution runs after authentication on every web request.
        $middleware->web(
            // /admin gets its own session cookie, so the two logins cannot
            // touch each other. Has to come before StartSession.
            prepend: [UseAdminSession::class],
            append: [SetBusinessContext::class],
        );

        // A signed-out visitor to /admin goes to the admin sign-in. Anywhere
        // else nothing changes: the API answers 401 and there is no web login.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('admin', 'admin/*') ? route('admin.login') : null
        );

        $middleware->alias([
            'business' => SetBusinessContext::class,
            'active' => EnsureAccountIsActive::class,
            'writable' => EnsureBusinessIsWritable::class,
            'admin.active' => EnsureAdminIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A broken rule renders as a 422 with a machine-readable code, so the
        // selling screen can mark the one line that is out of stock rather
        // than failing the whole sale with a generic message.
        $exceptions->render(fn (DomainException $e) => $e->render());
    })->create();
