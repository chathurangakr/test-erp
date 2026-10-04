<?php

namespace App\Http\Requests\Receivables;

use App\Http\Requests\Concerns\ValidatesPaymentMethod;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a DRAFT customer receipt.
 *
 * The allocated total must equal the receipt exactly — checked at posting, where the invoices
 * are locked and their outstanding balances are authoritative.
 */
class StoreCustomerReceiptRequest extends FormRequest
{
    use ValidatesPaymentMethod;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where('is_active', true)],
            'receipt_date' => ['required', 'date'],
            'receipt_account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'numeric', new Money, 'gt:0'],

            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.sales_invoice_id' => ['required', 'integer', Rule::exists('sales_invoices', 'id')],
            'allocations.*.amount' => ['required', 'numeric', new Money, 'gt:0'],
        ] + $this->paymentMethodRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->paymentMethodMessages() + [
            'allocations.min' => __('A receipt must be allocated to at least one invoice. Receipts on account are not supported.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateMethodAgainstAccount($validator, 'receipt_account_id'));
    }
}
