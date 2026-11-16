<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'category_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('categories', 'id')->where('business_id', $this->user()->business_id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
