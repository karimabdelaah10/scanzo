<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // `exists` runs unscoped, so it is tied to this tenant by hand.
            // A category cannot be its own parent, nor sit under one of its own
            // descendants: a loop would drop its whole branch off the two-level
            // list, with no top-level entry left to hang it from.
            'parent_id' => [
                'nullable', 'uuid',
                Rule::exists('categories', 'id')->where('business_id', $this->user()->business_id),
                Rule::notIn([$this->route('category')]),
                fn (string $attribute, mixed $value, \Closure $fail) => $this->formsALoop($value)
                    ? $fail('A category cannot be placed under one of its own sub-categories.')
                    : null,
            ],
        ];
    }

    /** Walks up from the proposed parent; meeting this category on the way is a loop. */
    private function formsALoop(?string $parentId): bool
    {
        $self = $this->route('category');

        if ($self === null || $parentId === null) {
            return false;
        }

        $seen = [];

        // `seen` also guards against a loop already in the data: it must end.
        while ($parentId !== null && ! isset($seen[$parentId])) {
            if ($parentId === $self) {
                return true;
            }

            $seen[$parentId] = true;
            $parentId = Category::whereKey($parentId)->value('parent_id');
        }

        return false;
    }
}
