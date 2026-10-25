<?php

namespace App\Http\Requests\Loans;

use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Correcting a loan's description and reminder schedule — never its money figures.
 */
class UpdateLoanRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::scheduleRules();
    }

    /**
     * @return array<string, array<mixed>>
     */
    public static function scheduleRules(): array
    {
        return [
            'lender' => ['required', 'string', 'max:150'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'has_interest' => ['boolean'],
            // % a year on what is still owed — only suggests each payment's interest.
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,4'],
            'installment_amount' => ['nullable', 'numeric', 'gt:0', new Money],
            'due_day' => ['nullable', 'integer', 'between:1,31'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
