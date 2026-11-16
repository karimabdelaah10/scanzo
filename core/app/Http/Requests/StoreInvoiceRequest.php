<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreInvoiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Supplied by the device so a retried sync cannot duplicate the sale.
            'id' => ['nullable', 'uuid'],
            // `exists` runs unscoped, so it is constrained to this tenant by
            // hand — otherwise another business's device id would pass.
            'device_id' => [
                'required', 'uuid',
                Rule::exists('devices', 'id')->where('business_id', $this->user()->business_id),
            ],

            'items' => ['required', 'array', 'min:1'],
            'items.*.variant_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            // A line discount as money. A percentage is the till's way of
            // typing it; only the amount is ever sent and stored.
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],

            // Prices are read from the catalogue, never from the request.
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            // Mixed is refused until split-amount payments exist: it would
            // record a split with no amounts behind it.
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)->except(PaymentMethod::Mixed)],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],

            // The device's own clock: this is what the receipt shows.
            'issued_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'An invoice needs at least one item.',
        ];
    }
}
