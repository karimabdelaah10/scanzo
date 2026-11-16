<?php

namespace App\Http\Requests;

use App\Support\Labels\LabelTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSettingsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'tax_number' => ['sometimes', 'nullable', 'string', 'max:64'],

            'returns' => ['sometimes', 'array'],
            'returns.window_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'returns.sellers_allowed' => ['sometimes', 'boolean'],
            'returns.without_invoice' => ['sometimes', 'boolean'],

            // Whether a seller may discount at all (a line or the whole
            // invoice), and how far. Managers and owners have no cap.
            'discounts' => ['sometimes', 'array'],
            'discounts.sellers_allowed' => ['sometimes', 'boolean'],
            'discounts.max_percent' => ['sometimes', 'integer', 'min:0', 'max:100'],

            'voids' => ['sometimes', 'array'],
            'voids.window_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],

            // Days in transit before a transfer is marked late on the transfers screen.
            'transfers' => ['sometimes', 'array'],
            'transfers.late_after_days' => ['sometimes', 'integer', 'min:1', 'max:90'],

            'selling' => ['sometimes', 'array'],
            // Whether a scan drops straight into the cart or opens the item
            // first. The pilot shop decides the default.
            'selling.quick_scan' => ['sometimes', 'boolean'],

            'labels' => ['sometimes', 'array'],
            'labels.size' => ['sometimes', Rule::enum(LabelTemplate::class)],
            'labels.show_price' => ['sometimes', 'boolean'],

            'receipt' => ['sometimes', 'array'],
            'receipt.header' => ['sometimes', 'nullable', 'string', 'max:300'],
            'receipt.footer' => ['sometimes', 'nullable', 'string', 'max:300'],

            // The two brand colours, #RRGGBB, or null to go back to the
            // platform's. The logo is not here: it is a file, uploaded on its
            // own (POST /api/settings/logo).
            'brand' => ['sometimes', 'array'],
            'brand.primary' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand.secondary' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],

            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
