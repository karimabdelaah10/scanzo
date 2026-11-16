<?php

namespace App\Http\Requests\Admin;

use App\Rules\BrandLogo;
use Illuminate\Http\Request;

/**
 * The brand inputs of the platform panel's forms (new business, business page),
 * kept in one place so the two cannot validate differently.
 *
 * A colour picker always holds a colour, so "no colour" is its own checkbox:
 * `clear_primary` / `clear_secondary` turn the field into null.
 */
class BrandFields
{
    public static function rules(): array
    {
        return [
            'brand_primary' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brand_secondary' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'logo' => ['nullable', new BrandLogo],
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public static function prepare(Request $request): array
    {
        $merge = [];

        foreach (['primary', 'secondary'] as $key) {
            if ($request->boolean("clear_{$key}")) {
                $merge["brand_{$key}"] = null;
            }
        }

        return $merge;
    }

    /**
     * The colours that were part of the submission, shaped for UpdateBrand.
     *
     * @return array{primary?: ?string, secondary?: ?string}
     */
    public static function colours(Request $request): array
    {
        $colours = [];

        foreach (['primary', 'secondary'] as $key) {
            if ($request->has("brand_{$key}") || $request->boolean("clear_{$key}")) {
                $colours[$key] = $request->input("brand_{$key}");
            }
        }

        return $colours;
    }
}
