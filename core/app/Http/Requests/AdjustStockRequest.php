<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdjustStockRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => [
                'required', 'uuid',
                Rule::exists('branches', 'id')->where('business_id', $this->user()->business_id),
            ],
            'items' => ['required', 'array', 'min:1', 'max:1000'],
            'items.*.variant_id' => ['required', 'uuid'],
            // What was counted on the shelf, not a difference.
            'items.*.counted' => ['required', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
