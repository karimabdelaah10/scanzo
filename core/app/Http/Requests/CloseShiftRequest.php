<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CloseShiftRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // What was actually in the drawer. Required: a close without a
            // count is the automatic one, and only the system makes that.
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:9999999'],
            // Where the seller explains a shortage.
            'note' => ['nullable', 'string', 'max:500'],
            // The device's own clock.
            'closed_at' => ['nullable', 'date'],
        ];
    }
}
