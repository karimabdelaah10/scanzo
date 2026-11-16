<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReceiveTransferRequest extends FormRequest
{
    public function rules(): array
    {
        // Which lines belong to the transfer is checked against the transfer
        // itself, under its lock, in ReceiveTransfer. Zero is a real answer.
        return [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.variant_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }
}
