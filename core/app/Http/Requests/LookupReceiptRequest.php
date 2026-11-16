<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LookupReceiptRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Scanned off the receipt's QR, or typed in when the receipt is gone.
            'number' => ['required', 'string', 'max:32'],
        ];
    }
}
