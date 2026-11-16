<?php

namespace App\Http\Requests\Admin;

use App\Enums\SubscriptionStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin') !== null;
    }

    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', 'max:64'],
            'status' => ['required', Rule::enum(SubscriptionStatus::class)],
            'starts_at' => ['required', 'date'],
            // Empty means no end date.
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'offline_grace_days' => ['required', 'integer', 'min:1', 'max:365'],
            // Empty means unlimited.
            'max_branches' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'max_users' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'max_devices' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
