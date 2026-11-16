<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SettleTransferShortageRequest extends FormRequest
{
    public function rules(): array
    {
        // Whether the branch belongs to the transfer, and whether the lines
        // belong to it, is checked against the transfer under its lock in
        // SettleTransferShortage. The reason is checked there too, after trim,
        // so a string of spaces does not count as one.
        return [
            'branch_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.variant_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }
}
