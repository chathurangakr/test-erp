<?php

namespace App\Http\Requests\Receivables;

use App\Enums\PaymentMethod;
use App\Enums\SalesCreditNoteKind;
use App\Enums\SalesCreditNoteSettlement;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A discount or quality allowance against one invoice, as a credit or as money back. Whether the
 * invoice is still owed (or was paid) that much is checked by RecordSalesCreditNote, under lock.
 */
class StoreSalesCreditNoteRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'credit_date' => ['required', 'date_format:Y-m-d'],
            'sales_invoice_id' => ['required', 'integer', 'exists:sales_invoices,id'],
            'kind' => ['required', Rule::enum(SalesCreditNoteKind::class)],
            'settlement' => ['sometimes', Rule::enum(SalesCreditNoteSettlement::class)],
            'cash_bank_account_id' => ['required_if:settlement,REFUND', 'nullable', 'integer', Rule::exists('cash_bank_accounts', 'id')],
            'payment_method' => ['required_if:settlement,REFUND', 'nullable', Rule::enum(PaymentMethod::class)],
            'amount' => ['required', 'numeric', new Money, 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
