<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    public function rules(): array
    {
        return BrandFields::rules();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(BrandFields::prepare($this));
    }
}
