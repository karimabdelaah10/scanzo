<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferStockRequest extends FormRequest
{
    public function rules(): array
    {
        $businessId = $this->user()->business_id;

        return [
            'from_branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')->where('business_id', $businessId)],
            'to_branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')->where('business_id', $businessId)],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.variant_id' => [
                'required', 'uuid',
                Rule::exists('product_variants', 'id')->where('business_id', $businessId),
            ],
            // Zero or negative would be a transfer the other way, with nobody's say-so.
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
