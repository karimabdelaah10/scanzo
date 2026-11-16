<?php

namespace App\Http\Requests;

use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDeviceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'branch_id' => [
                'sometimes', 'required', 'uuid',
                Rule::exists('branches', 'id')->where('business_id', $this->user()->business_id),
            ],
            // The code is not editable: invoices already carry it in their
            // numbers, and changing it would break the sequence.
            'type' => ['sometimes', Rule::enum(DeviceType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
