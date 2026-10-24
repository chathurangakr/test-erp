<?php

namespace App\Http\Requests\Receivables;

use App\Enums\SalesCreditNoteKind;
use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A discount or quality allowance against one invoice. Whether the invoice is still owed that
 * much is checked by RecordSalesCreditNote, under lock.
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
            'amount' => ['required', 'numeric', new Money, 'gt:0'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
