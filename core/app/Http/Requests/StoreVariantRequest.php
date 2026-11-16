<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVariantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'sku' => ['nullable', 'string', 'max:64'],
            'barcode' => ['nullable', 'string', 'max:64'],
            'size' => ['nullable', 'string', 'max:32'],
            'color' => ['nullable', 'string', 'max:32'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'opening_stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'opening_stock_branch_id' => [
                'nullable', 'uuid', 'required_with:opening_stock',
                Rule::exists('branches', 'id')->where('business_id', $this->user()->business_id),
            ],
        ];
    }
}
