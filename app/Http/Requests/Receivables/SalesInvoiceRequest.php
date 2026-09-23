<?php

namespace App\Http\Requests\Receivables;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared rules for creating and editing a DRAFT sales invoice.
 *
 * Note what is absent: any revenue account. Each line's revenue account is resolved from its
 * product's mapping at posting time, so a user says what is being billed and never where the
 * credit lands.
 *
 * The remaining-value check lives in PostSalesInvoice, not here — a draft may be saved while
 * someone works out the figures, and the value it is checked against is only meaningful under
 * the lock taken at posting time.
 */
abstract class SalesInvoiceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('is_active', true)],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'customer_reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],

            'lines' => ['required', 'array', 'min:1'],
            'lines.*.delivery_line_id' => ['required', 'integer', Rule::exists('delivery_lines', 'id')],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'due_date.after_or_equal' => __('The due date cannot fall before the invoice date.'),
            'lines.min' => __('A sales invoice needs at least one delivered line to bill.'),
        ];
    }
}
