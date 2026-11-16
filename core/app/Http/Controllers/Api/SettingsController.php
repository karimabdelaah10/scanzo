<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Actions\Brand\UpdateBrand;
use App\Rules\BrandLogo;
use App\Services\SubscriptionLimits;
use App\Support\Brand\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class SettingsController extends Controller
{
    public function __construct(protected SubscriptionLimits $limits) {}

    /** GET /api/settings — the shop's own settings, plan and what it has used. */
    public function show(Request $request): JsonResponse
    {
        $business = $request->user()->loadMissing('business.subscription')->business;

        return response()->json([
            'data' => [
                'name' => $business->name,
                'phone' => $business->phone,
                'tax_number' => $business->tax_number,
                'status' => $business->status->value,
                'currency' => $business->currency(),
                'returns' => [
                    'window_days' => $business->returnWindowDays(),
                    'sellers_allowed' => $business->sellersMayReturn(),
                    'without_invoice' => $business->allowsReturnWithoutInvoice(),
                ],
                'discounts' => [
                    'sellers_allowed' => $business->sellersMayDiscount(),
                    'max_percent' => $business->discountMaxPercent(),
                ],
                'voids' => [
                    'window_hours' => $business->voidWindowHours(),
                ],
                'transfers' => [
                    'late_after_days' => $business->transferLateAfterDays(),
                ],
                'selling' => [
                    'quick_scan' => $business->quickScan(),
                ],
                'labels' => [
                    'size' => $business->labelTemplate()->value,
                    'show_price' => (bool) $business->setting('labels.show_price', true),
                ],
                'receipt' => [
                    'header' => (string) $business->setting('receipt.header', ''),
                    'footer' => (string) $business->setting('receipt.footer', ''),
                ],
                // One logo for the sidebar, the till header and the receipt.
                'brand' => Brand::for($business),
            ],
            'subscription' => [
                'plan' => $business->subscription?->plan,
                'status' => $business->subscription?->status->value,
                'ends_at' => $business->subscription?->ends_at?->toIso8601String(),
                'offline_grace_days' => $business->subscription?->offline_grace_days,
            ],
            'usage' => $this->limits->usage($business),
        ]);
    }

    /**
     * PATCH /api/settings
     *
     * The settings column is merged, not replaced, so sending one switch does
     * not silently reset the others.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        if (Gate::denies('manage-business')) {
            throw DomainException::notAllowedToManageBusiness();
        }

        $business = $request->user()->loadMissing('business')->business;
        $data = $request->validated();

        $columns = Arr::only($data, ['name', 'phone', 'tax_number']);

        if ($columns !== []) {
            $business->fill($columns);
        }

        $settings = $business->settings ?? [];

        foreach (['returns', 'discounts', 'voids', 'transfers', 'selling', 'labels', 'receipt'] as $group) {
            if (array_key_exists($group, $data)) {
                $settings[$group] = array_replace($settings[$group] ?? [], $data[$group]);
            }
        }

        if (array_key_exists('currency', $data)) {
            $settings['currency'] = strtoupper($data['currency']);
        }

        $business->settings = $settings;
        $business->save();

        if (array_key_exists('brand', $data)) {
            app(UpdateBrand::class)->handle($business, Arr::only($data['brand'], ['primary', 'secondary']));
        }

        return $this->show($request);
    }

    /** POST /api/settings/logo — replaces the shop's logo (multipart, field `logo`). */
    public function uploadLogo(Request $request, UpdateBrand $brand): JsonResponse
    {
        if (Gate::denies('manage-business')) {
            throw DomainException::notAllowedToManageBusiness();
        }

        $request->validate(['logo' => ['required', new BrandLogo]]);

        $brand->handle($request->user()->loadMissing('business')->business, logo: $request->file('logo'));

        return $this->show($request);
    }

    /** DELETE /api/settings/logo */
    public function removeLogo(Request $request, UpdateBrand $brand): JsonResponse
    {
        if (Gate::denies('manage-business')) {
            throw DomainException::notAllowedToManageBusiness();
        }

        $brand->handle($request->user()->loadMissing('business')->business, removeLogo: true);

        return $this->show($request);
    }

    /**
     * GET /api/brand/preview?primary=#RRGGBB&secondary=#RRGGBB
     *
     * What the colours would become, without saving: the settings screen's live
     * preview asks here, so the contrast rule exists in one place only.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'primary' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        return response()->json(['data' => Brand::resolve($data['primary'] ?? null, $data['secondary'] ?? null)]);
    }
}
