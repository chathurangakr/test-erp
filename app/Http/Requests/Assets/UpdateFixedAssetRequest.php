<?php

namespace App\Http\Requests\Assets;

use App\Enums\AssetCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Correcting an asset's name, kind and description — never its purchase figures.
 */
class UpdateFixedAssetRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::detailRules();
    }

    /**
     * @return array<string, array<mixed>>
     */
    public static function detailRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::enum(AssetCategory::class)],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
