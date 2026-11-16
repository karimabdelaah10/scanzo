<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use App\Support\Brand\Brand;
use App\Support\BusinessContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/login
     *
     * Session based: the front end is same-origin, so there is no token to
     * store or refresh. A user's email is unique across the platform, so there
     * is nothing to ask before the password — see docs/decisions.md 12.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = strtolower((string) $request->input('email'));
        $throttleKey = $email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '
                    .RateLimiter::availableIn($throttleKey).' seconds.',
            ]);
        }

        // No tenant is set yet, so BusinessScope does nothing here — which is
        // what lets a user be found before we know which business they are in.
        $user = User::where('email', $email)->first();

        if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages([
                'email' => 'These details do not match our records.',
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => 'This account has been switched off.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        Auth::guard('web')->login($user, remember: $request->boolean('remember'));
        $request->session()->regenerate();

        return $this->payload($request->user());
    }

    /** POST /api/logout */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * GET /api/me
     *
     * Everything the front end needs to start: who is signed in, the shop's
     * settings, and the branches and tills they may use. One call instead of
     * four on every page load.
     */
    public function me(Request $request): JsonResponse
    {
        return $this->payload($request->user());
    }

    protected function payload(User $user): JsonResponse
    {
        $user->loadMissing('business');

        app(BusinessContext::class)->setBusinessId($user->business_id);

        // After the tenant is set: Subscription is scoped like any tenant model.
        $user->business->loadMissing('subscription');
        $subscription = $user->business->subscription;

        $branches = Branch::query()
            ->where('is_active', true)
            ->unless($user->role->seesAllBranches(), fn ($q) => $q->where('id', $user->branch_id))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $devices = Device::query()
            ->where('is_active', true)
            ->whereIn('branch_id', $branches->pluck('id'))
            ->orderBy('code')
            ->get(['id', 'branch_id', 'name', 'code', 'type', 'last_sync_at']);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'branch_id' => $user->branch_id,
                'sees_all_branches' => $user->role->seesAllBranches(),
                'sees_cost' => $user->role->seesCost(),
            ],
            'business' => [
                'id' => $user->business->id,
                'name' => $user->business->name,
                'currency' => $user->business->currency(),
                'label_size' => $user->business->labelTemplate()->value,
                // Colours, logo and the foregrounds derived from them (never
                // stored). The front end caches this so the next load paints
                // in the shop's colours at once.
                'brand' => Brand::for($user->business),
                'status' => $user->business->status->value,
                // True when suspended: every write is refused. The till shows
                // "selling is stopped" and the dashboard its read-only banner.
                'read_only' => $user->business->status->isReadOnly(),
                'quick_scan' => $user->business->quickScan(),
                'return_window_days' => $user->business->returnWindowDays(),
                'sellers_may_return' => $user->business->sellersMayReturn(),
                // The till shows its discount controls (line and invoice) only to
                // someone the server will accept them from, and caps the percentage.
                'can_discount' => $user->canGiveDiscounts(),
                'discount_max_percent' => $user->isSeller() ? $user->business->discountMaxPercent() : 100,
            ],
            // For the owner's banner. It informs and never blocks: an ended
            // subscription keeps selling. `state` is active, expiring, expired
            // or none (no subscription row); days_left is null unless a future
            // end date is set.
            'subscription' => [
                'state' => $subscription?->state() ?? 'none',
                'days_left' => $subscription?->daysLeft(),
                'ends_at' => $subscription?->ends_at?->toIso8601String(),
            ],
            'branches' => $branches,
            'devices' => $devices,
            // What the till offers at checkout. Mixed is left out until a split
            // payment records its amounts: "mixed" alone is a value with no
            // meaning.
            'payment_methods' => collect(PaymentMethod::cases())
                ->reject(fn (PaymentMethod $m) => $m === PaymentMethod::Mixed)
                ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()])
                ->values(),
        ]);
    }
}
