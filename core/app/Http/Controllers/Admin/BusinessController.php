<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Brand\UpdateBrand;
use App\Actions\Platform\OnboardBusiness;
use App\Http\Requests\Admin\BrandFields;
use App\Http\Requests\Admin\UpdateBrandRequest;
use App\Support\Brand\Brand;
use Illuminate\Http\JsonResponse;
use App\Enums\BusinessStatus;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBusinessRequest;
use App\Http\Requests\Admin\UpdateSubscriptionRequest;
use App\Models\Business;
use App\Models\Scopes\BusinessScope;
use App\Models\Subscription;
use App\Services\SubscriptionLimits;
use App\Support\BusinessContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The platform panel's businesses screens.
 *
 * Everything here reads across tenants, and BusinessScope only steps aside
 * when no tenant is set. That is true on a clean request (EnsureAdminIsActive
 * clears it), but a panel that depends on "nothing happened to set one" breaks
 * silently, so every tenant model queried below has the scope removed by name.
 * The one exception is the usage numbers on the show page, which go through
 * SubscriptionLimits: that service counts "the current tenant", so it is run
 * as that business with BusinessContext::runFor() instead.
 */
class BusinessController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(BusinessStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            // Who to call: an ended subscription, one about to end, or
            // 'attention' — ended plus shops with no subscription row at all.
            'subscription' => ['nullable', Rule::in(['attention', 'expired', 'expiring'])],
        ]);

        $unscoped = fn ($query) => $query->withoutGlobalScope(BusinessScope::class);

        $businesses = Business::query()
            ->with(['subscription' => $unscoped])
            // Same definitions as SubscriptionLimits::usage(): every branch,
            // active users, active devices.
            ->withCount([
                'branches' => $unscoped,
                'users' => fn ($q) => $unscoped($q)->where('is_active', true),
                'devices' => fn ($q) => $unscoped($q)->where('is_active', true),
            ])
            ->withMax(['invoices as last_invoice_at' => $unscoped], 'issued_at')
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            // The same two definitions as Subscription::state(), as SQL.
            ->when($data['subscription'] ?? null, fn ($q, $which) => $which === 'attention'
                // A shop with no subscription is unpaid-for and invisible
                // everywhere else, so it sits with the expired ones.
                ? $q->where(fn ($q) => $q
                    ->whereDoesntHave('subscription', $unscoped)
                    ->orWhereHas('subscription', fn ($sub) => $this->subscriptionFilter($unscoped($sub), 'expired')))
                : $q->whereHas('subscription', fn ($sub) => $this->subscriptionFilter($unscoped($sub), $which)))
            ->when($data['q'] ?? null, function ($q, $term) {
                $like = '%'.addcslashes($term, '\\%_').'%';

                $q->where('name', 'like', $like);
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('admin.businesses.index', [
            'businesses' => $businesses,
            'statuses' => BusinessStatus::cases(),
            'filters' => $data,
        ]);
    }

    /**
     * Expired: not active, or its end has passed. Expiring: active, ending
     * inside the warning window — the last day counts, as in daysLeft().
     */
    private function subscriptionFilter($query, string $which)
    {
        if ($which === 'expired') {
            return $query->where(fn ($q) => $q
                ->where('status', '!=', SubscriptionStatus::Active)
                ->orWhere('ends_at', '<=', now()));
        }

        return $query
            ->where('status', SubscriptionStatus::Active)
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addDays((int) config('scanzo.subscription_warning_days', 7))->endOfDay());
    }

    public function create(): View
    {
        return view('admin.businesses.create');
    }

    public function store(StoreBusinessRequest $request, OnboardBusiness $onboard, UpdateBrand $updateBrand): Response
    {
        // The owner's password is generated here and shown once on the page
        // this returns. It is not flashed to the session and not redirected:
        // a redirect would mean writing a plaintext password into the session
        // store. The cost is that a refresh re-submits the form, which then
        // fails on the email being taken — the safe way round.
        $password = Str::password(12, symbols: false);

        $result = $onboard->handle(
            businessName: $request->string('business_name')->toString(),
            ownerName: $request->string('owner_name')->toString(),
            ownerEmail: $request->string('owner_email')->toString(),
            ownerPassword: $password,
            phone: $request->input('phone'),
            plan: $request->string('plan')->toString(),
            trialDays: $request->integer('trial_days'),
        );

        // The brand is optional at creation; the same action the shop's own
        // settings use writes it, so there is one way a brand gets saved.
        $colours = BrandFields::colours($request);
        $logo = $request->file('logo');

        if ($colours !== [] || $logo !== null) {
            $updateBrand->handle($result['business'], $colours, $logo);
        }

        Log::info('Platform: business onboarded', [
            'admin_id' => $request->user('admin')->id,
            'business_id' => $result['business']->id,
        ]);

        return response()->view('admin.businesses.created', [
            'business' => $result['business'],
            'subscription' => $result['subscription'],
            'owner' => $result['owner'],
            'password' => $password,
            'loginUrl' => url('/till'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function show(Business $business, SubscriptionLimits $limits): View
    {
        $business->load(['subscription' => fn ($q) => $q->withoutGlobalScope(BusinessScope::class)]);

        $usage = app(BusinessContext::class)->runFor(
            $business->id,
            fn () => $limits->usage($business),
        );

        $lastInvoiceAt = $business->invoices()
            ->withoutGlobalScope(BusinessScope::class)
            ->max('issued_at');

        return view('admin.businesses.show', [
            'business' => $business,
            'subscription' => $business->subscription,
            'usage' => $usage,
            'lastInvoiceAt' => $lastInvoiceAt ? Carbon::parse($lastInvoiceAt) : null,
            'statuses' => BusinessStatus::cases(),
            'brand' => Brand::for($business),
        ]);
    }

    public function updateBrand(UpdateBrandRequest $request, Business $business, UpdateBrand $updateBrand): RedirectResponse
    {
        $updateBrand->handle(
            $business,
            BrandFields::colours($request),
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        Log::info('Platform: business brand updated', [
            'admin_id' => $request->user('admin')->id,
            'business_id' => $business->id,
        ]);

        return redirect()->route('admin.businesses.show', $business)->with('status', 'اتحفظ الشكل (الألوان واللوجو).');
    }

    /** GET /admin/brand/preview — what two colours become, for the live preview. */
    public function previewBrand(Request $request): JsonResponse
    {
        $data = $request->validate([
            'primary' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        return response()->json(['data' => Brand::resolve($data['primary'] ?? null, $data['secondary'] ?? null)]);
    }

    public function updateSubscription(UpdateSubscriptionRequest $request, Business $business): RedirectResponse
    {
        $data = $request->validated();

        $attributes = [
            'plan' => $data['plan'],
            'status' => $data['status'],
            'starts_at' => Carbon::parse($data['starts_at'])->startOfDay(),
            // The last day counts in full: a subscription ending on the 30th
            // is live until the end of the 30th, not its first second.
            'ends_at' => filled($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at'])->endOfDay() : null,
            'offline_grace_days' => $data['offline_grace_days'],
            'max_branches' => $data['max_branches'] ?? null,
            'max_users' => $data['max_users'] ?? null,
            'max_devices' => $data['max_devices'] ?? null,
            'note' => $data['note'] ?? null,
        ];

        $subscription = $business->subscription()
            ->withoutGlobalScope(BusinessScope::class)
            ->first();

        if ($subscription !== null) {
            $subscription->update($attributes);
        } else {
            // business_id is passed explicitly: with no tenant set, the
            // creating hook has nothing to stamp and would refuse.
            Subscription::create($attributes + ['business_id' => $business->id]);
        }

        Log::info('Platform: subscription updated', [
            'admin_id' => $request->user('admin')->id,
            'business_id' => $business->id,
        ]);

        return redirect()->route('admin.businesses.show', $business)
            ->with('status', 'اتحفظ الاشتراك.');
    }

    public function updateStatus(Request $request, Business $business): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(BusinessStatus::class)],
        ]);

        $new = BusinessStatus::from($data['status']);
        $old = $business->status;

        $business->update(['status' => $new]);

        Log::warning('Platform: business status changed', [
            'admin_id' => $request->user('admin')->id,
            'business_id' => $business->id,
            'from' => $old->value,
            'to' => $new->value,
        ]);

        return redirect()->route('admin.businesses.show', $business)
            ->with('status', $new === BusinessStatus::Suspended
                ? 'اتوقف البيع والمرتجعات للمحل ده.'
                : 'اتحدثت حالة المحل.');
    }
}
