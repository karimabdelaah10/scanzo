<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReceiveStockRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'branch_id' => [
                'required', 'uuid',
                Rule::exists('branches', 'id')->where('business_id', $this->user()->business_id),
            ],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.variant_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
