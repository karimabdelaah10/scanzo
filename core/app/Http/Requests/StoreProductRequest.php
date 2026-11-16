<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function rules(): array
    {
        $businessId = $this->user()->business_id;

        return [
            // Supplied by the device so a retried save is not a second product.
            'id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => [
                'nullable', 'uuid',
                Rule::exists('categories', 'id')->where('business_id', $businessId),
            ],

            // A product with no size or colour cannot be labelled or sold, so
            // at least one is required.
            'variants' => ['required', 'array', 'min:1', 'max:200'],
            // Left out, the code is generated: nobody types SKUs for hundreds
            // of pieces.
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'variants.*.size' => ['nullable', 'string', 'max:32'],
            'variants.*.color' => ['nullable', 'string', 'max:32'],
            'variants.*.price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'variants.*.cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'variants.*.opening_stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],

            // Where the opening stock lands. Required only if any was given.
            'opening_stock_branch_id' => [
                'nullable', 'uuid',
                Rule::exists('branches', 'id')->where('business_id', $businessId),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $hasOpeningStock = collect($this->input('variants', []))
                ->contains(fn ($variant) => (int) ($variant['opening_stock'] ?? 0) > 0);

            if ($hasOpeningStock && blank($this->input('opening_stock_branch_id'))) {
                $validator->errors()->add(
                    'opening_stock_branch_id',
                    'Say which branch the opening stock is in.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'variants.required' => 'A product needs at least one size or colour.',
        ];
    }
}
