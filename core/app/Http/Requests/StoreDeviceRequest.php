<?php

namespace App\Http\Requests;

use App\Enums\DeviceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeviceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'branch_id' => [
                'required', 'uuid',
                Rule::exists('branches', 'id')->where('business_id', $this->user()->business_id),
            ],
            // Left out, it is generated. The code becomes the invoice number
            // prefix, so a shop inventing a duplicate would be a real problem.
            'code' => [
                'nullable', 'string', 'max:4', 'alpha_num',
                Rule::unique('devices', 'code')->where('business_id', $this->user()->business_id),
            ],
            'type' => ['nullable', Rule::enum(DeviceType::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (filled($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
