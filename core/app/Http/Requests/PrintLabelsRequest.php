<?php

namespace App\Http\Requests;

use App\Support\Labels\LabelTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PrintLabelsRequest extends FormRequest
{
    /**
     * One label per piece on the shelf is the default, so the branch rule has
     * to see that value even when the request leaves it out — otherwise the
     * missing branch slips through validation and fails later as "nothing to
     * print", which explains nothing.
     */
    protected function prepareForValidation(): void
    {
        if (blank($this->input('copies'))) {
            $this->merge(['copies' => 'stock']);
        }
    }

    public function rules(): array
    {
        $businessId = $this->user()->business_id;

        return [
            'template' => ['nullable', Rule::enum(LabelTemplate::class)],

            // Either a set of variants, or every variant of one product.
            'variant_ids' => ['nullable', 'array', 'max:500'],
            'variant_ids.*' => ['uuid'],
            'product_id' => ['nullable', 'uuid'],

            // `exists` runs unscoped, so it is tied to this tenant by hand.
            'branch_id' => [
                'nullable', 'uuid', 'required_if:copies,stock',
                Rule::exists('branches', 'id')->where('business_id', $businessId),
            ],

            // One per piece on the shelf, one each, or a number you name.
            'copies' => ['nullable', 'in:stock,one,fixed'],
            'count' => ['nullable', 'integer', 'min:1', 'max:500', 'required_if:copies,fixed'],

            'show_price' => ['nullable', 'boolean'],
            'show_barcode' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'branch_id.required_if' => 'Say which branch, so the stock can be counted for the number of labels.',
        ];
    }
}
