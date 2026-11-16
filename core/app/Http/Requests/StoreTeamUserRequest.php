<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreTeamUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Unique across the platform, not per business: one account
            // belongs to one shop, which keeps the login form to one step.
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', Password::min(8)],
            'role' => ['required', Rule::enum(Role::class)],
            // Required for everyone but an owner, who works across all of them.
            'branch_id' => [
                'nullable', 'uuid', 'required_unless:role,owner',
                Rule::exists('branches', 'id')->where('business_id', $this->user()->business_id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required_unless' => 'Say which branch this person works in.',
            'email.unique' => 'That email already has an account.',
        ];
    }
}
