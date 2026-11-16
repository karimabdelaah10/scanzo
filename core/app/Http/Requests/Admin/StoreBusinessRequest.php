<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'owner_name' => ['required', 'string', 'max:255'],
            // Unique across the platform (decision 12). Plain `unique:` is a
            // query-builder check, so it sees every tenant, which is the point.
            'owner_email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'plan' => ['required', 'string', 'max:64'],
            'trial_days' => ['required', 'integer', 'min:1', 'max:365'],
            ...BrandFields::rules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'owner_email' => strtolower(trim((string) $this->input('owner_email'))),
            ...BrandFields::prepare($this),
        ]);
    }
}
