<?php

namespace App\Http\Middleware;

use App\Support\BusinessContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform panel's counterpart of EnsureAccountIsActive: an admin who was
 * switched off after signing in loses access on the next request, not when the
 * session happens to expire. Runs after `auth:admin`.
 *
 * It also clears the tenant. The panel reads across every business, so no
 * BusinessContext may be left over from anything earlier in the process; the
 * controllers still remove BusinessScope explicitly, so this is the second
 * lock, not the only one.
 */
class EnsureAdminIsActive
{
    public function __construct(protected BusinessContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->forgetBusiness();

        $admin = Auth::guard('admin')->user();

        if ($admin !== null && ! $admin->is_active) {
            Auth::guard('admin')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')
                ->withErrors(['email' => 'هذا الحساب موقوف.']);
        }

        return $next($request);
    }
}
