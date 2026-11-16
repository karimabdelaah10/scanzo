<?php

namespace App\Http\Requests;

use App\Enums\ItemCondition;
use App\Enums\PaymentMethod;
use App\Enums\RefundMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleReturnRequest extends FormRequest
{
    public function rules(): array
    {
        $businessId = $this->user()->business_id;

        return [
            // Device-supplied, so a retried sync cannot refund twice.
            'id' => ['nullable', 'uuid'],

            // `exists` runs unscoped, so both are constrained to this tenant
            // by hand.
            'device_id' => [
                'required', 'uuid',
                Rule::exists('devices', 'id')->where('business_id', $businessId),
            ],
            'invoice_id' => [
                'required', 'uuid',
                Rule::exists('invoices', 'id')->where('business_id', $businessId),
            ],

            'items' => ['required', 'array', 'min:1'],
            'items.*.invoice_item_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.condition' => ['nullable', Rule::enum(ItemCondition::class)],
            'items.*.restock' => ['nullable', 'boolean'],

            'refund_method' => ['nullable', Rule::enum(RefundMethod::class)],
            'reason' => ['nullable', 'string', 'max:255'],

            // Only a branch manager may accept a return past the window.
            'override_window' => ['nullable', 'boolean'],

            'issued_at' => ['nullable', 'date'],

            // Present means this is an exchange: what goes out in place of
            // what came back.
            'exchange_items' => ['nullable', 'array', 'min:1'],
            'exchange_items.*.variant_id' => ['required_with:exchange_items', 'uuid'],
            'exchange_items.*.quantity' => ['required_with:exchange_items', 'integer', 'min:1', 'max:1000'],
            'exchange_items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            // Mixed is refused until split-amount payments exist: it would
            // record a split with no amounts behind it.
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)->except(PaymentMethod::Mixed)],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'A return needs at least one item.',
        ];
    }
}
