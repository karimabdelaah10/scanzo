<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVariantRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The code is on printed labels all over the shop, so it is not
            // editable here. A wrong code means a new variant and a reprint.
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'size' => ['sometimes', 'nullable', 'string', 'max:32'],
            'color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:9999999999'],
            'cost' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
