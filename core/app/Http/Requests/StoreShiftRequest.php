<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Supplied by the device, like an invoice's: a retried sync of an
            // offline shift cannot open it twice.
            'id' => ['nullable', 'uuid'],
            'device_id' => [
                'required', 'uuid',
                Rule::exists('devices', 'id')->where('business_id', $this->user()->business_id),
            ],
            // The change in the drawer in the morning. Zero is a real answer.
            'opening_float' => ['required', 'numeric', 'min:0', 'max:9999999'],
            // The device's own clock.
            'opened_at' => ['nullable', 'date'],
            // The till's time zone, to tell "yesterday's shift" from "today's".
            'tz' => ['nullable', 'string', 'max:64'],
        ];
    }
}
