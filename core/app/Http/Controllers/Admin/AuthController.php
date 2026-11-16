<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Platform staff sign-in. The `admin` guard, never `web`. */
class AuthController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.businesses.index');
        }

        return view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $email = strtolower($data['email']);
        $throttleKey = 'admin|'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'محاولات كتير. جرّب بعد '.RateLimiter::availableIn($throttleKey).' ثانية.',
            ]);
        }

        $admin = Admin::where('email', $email)->first();

        if ($admin === null || ! Hash::check($data['password'], $admin->password)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages(['email' => 'البيانات دي مش مطابقة.']);
        }

        // Checked here for the sign-in, and again on every request by
        // EnsureAdminIsActive for a session that is already open.
        if (! $admin->is_active) {
            throw ValidationException::withMessages(['email' => 'هذا الحساب موقوف.']);
        }

        RateLimiter::clear($throttleKey);

        Auth::guard('admin')->login($admin, remember: $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('admin.businesses.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();

        // Safe to invalidate the whole session: /admin has its own cookie
        // (UseAdminSession), so a shop login in the same browser is untouched.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
