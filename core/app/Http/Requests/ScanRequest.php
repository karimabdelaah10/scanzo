<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ScanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The QR carries a SKU; a USB scanner sends the 1D barcode.
            'code' => ['required', 'string', 'max:64'],

            // `exists` runs its own query with no global scope, so it is
            // constrained to this tenant by hand — otherwise another
            // business's branch id would pass validation.
            'branch_id' => [
                'required', 'uuid',
                Rule::exists('branches', 'id')->where('business_id', $this->user()->business_id),
            ],
        ];
    }
}
