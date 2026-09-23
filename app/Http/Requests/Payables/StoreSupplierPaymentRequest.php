<?php

namespace App\Http\Requests\Payables;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a DRAFT supplier payment.
 *
 * The allocated total must equal the payment exactly — checked at posting, where the
 * invoices are locked and their outstanding balances are authoritative.
 */
class StoreSupplierPaymentRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', Rule::exists('suppliers', 'id')->where('is_active', true)],
            'payment_date' => ['required', 'date'],
            'payment_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0'],

            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.supplier_invoice_id' => ['required', 'integer', Rule::exists('supplier_invoices', 'id')],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'allocations.min' => __('A payment must be allocated to at least one invoice. Payments on account are not supported.'),
        ];
    }
}
