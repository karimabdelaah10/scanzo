<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchVariantsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Two characters at least: one letter matches half the catalogue
            // and returns a page nobody can use.
            'q' => ['required', 'string', 'min:2', 'max:64'],

            // `exists` runs unscoped, so it is pinned to this tenant by hand.
            'branch_id' => [
                'required', 'uuid',
                Rule::exists('branches', 'id')->where('business_id', $this->user()->business_id),
            ],

            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }
}
