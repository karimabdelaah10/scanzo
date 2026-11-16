<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateTeamUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes', 'required', 'email', 'max:190',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            // Left out, the password is unchanged.
            'password' => ['sometimes', 'nullable', Password::min(8)],
            'role' => ['sometimes', Rule::enum(Role::class)],
            'branch_id' => [
                'sometimes', 'nullable', 'uuid',
                Rule::exists('branches', 'id')->where('business_id', $this->user()->business_id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
