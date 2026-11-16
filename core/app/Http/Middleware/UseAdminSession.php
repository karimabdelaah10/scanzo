<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives /admin its own session cookie.
 *
 * Two guards on one cookie share one session: signing a shop out calls
 * session()->invalidate(), which would sign the platform admin out of the
 * same browser, and the reverse. A separate cookie, scoped to /admin, makes
 * the two logins genuinely independent — and a shop's cookie is never even
 * sent to /admin.
 *
 * Must run before StartSession, which reads these values when it builds the
 * session; it is prepended to the web group for that reason.
 */
class UseAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('admin', 'admin/*')) {
            config([
                'session.cookie' => 'scanzo_admin_session',
                'session.path' => '/admin',
            ]);
        }

        return $next($request);
    }
}
