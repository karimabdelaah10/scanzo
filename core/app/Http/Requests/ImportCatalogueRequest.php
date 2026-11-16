<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportCatalogueRequest extends FormRequest
{
    public function rules(): array
    {
        $user = $this->user();

        return [
            // 2 MB: a thousand rows of text is a fraction of that, and a file
            // far past it is not a catalogue.
            'file' => ['required', 'file', 'max:2048'],

            // Where opening stock goes when a row names no branch.
            'branch_id' => [
                'nullable', 'uuid',
                Rule::exists('branches', 'id')->where('business_id', $user->business_id),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $branch = $this->input('branch_id');

            if (filled($branch) && ! $validator->errors()->has('branch_id') && ! $this->user()->canAccessBranch($branch)) {
                $validator->errors()->add('branch_id', 'You cannot work in this branch.');
            }
        });
    }
}
