<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Short, and unique in this business: it is part of nothing the
            // customer sees, but the shop uses it to tell branches apart.
            'code' => [
                'required', 'string', 'max:8', 'alpha_dash',
                Rule::unique('branches', 'code')->where('business_id', $this->user()->business_id),
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (filled($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
