<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a signed-in user whose account has been switched off since they
 * signed in.
 *
 * AuthController::login stops a switched-off user from starting a session, but
 * one who is already inside keeps a valid cookie. Gates answer "what may this
 * role do", never "is this account still live", so this runs on every request
 * of the protected group rather than being patched into each gate.
 *
 * It answers 401 and ends the session, so the front end treats it like any
 * lapsed session and returns to the sign-in form; the `account_inactive` code
 * lets it say why.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            throw DomainException::accountSwitchedOff();
        }

        return $next($request);
    }
}
