<?php

namespace App\Http\Requests\Products;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    /**
     * Normalise the code before validation so the uniqueness check is case-insensitive
     * in practice — "coconut" must collide with the existing "COCONUT".
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('products', 'code')],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
            'primary_uom_id' => ['required', 'integer', Rule::exists('units_of_measure', 'id')],
            'secondary_uom_id' => [
                'nullable',
                'integer',
                Rule::exists('units_of_measure', 'id'),
                'different:primary_uom_id',
            ],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'secondary_uom_id.different' => __('The secondary unit of measure must be different from the primary unit of measure.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'primary_uom_id' => __('primary unit of measure'),
            'secondary_uom_id' => __('secondary unit of measure'),
        ];
    }
}
