<?php

namespace App\Http\Requests\Payables;

use App\Http\Requests\Concerns\ValidatesPaymentMethod;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a DRAFT supplier payment.
 *
 * The allocated total must equal the payment exactly — checked at posting, where the
 * invoices are locked and their outstanding balances are authoritative.
 */
class StoreSupplierPaymentRequest extends FormRequest
{
    use ValidatesPaymentMethod;

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
            'amount' => ['required', 'numeric', new Money, 'gt:0'],

            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.supplier_invoice_id' => ['required', 'integer', Rule::exists('supplier_invoices', 'id')],
            'allocations.*.amount' => ['required', 'numeric', new Money, 'gt:0'],
        ] + $this->paymentMethodRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->paymentMethodMessages() + [
            'allocations.min' => __('A payment must be allocated to at least one invoice. Payments on account are not supported.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateMethodAgainstAccount($validator, 'payment_account_id'));
    }
}
