<?php

namespace App\Http\Requests\Advances;

use App\Rules\Money;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Setting part of an advance against an invoice. What is left of the advance and what the invoice
 * still owes are checked by the action, under lock.
 */
class ApplyAdvanceRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $invoices = $this->routeIs('supplier-advances.*') ? 'supplier_invoices' : 'sales_invoices';

        return [
            'invoice_id' => ['required', 'integer', "exists:{$invoices},id"],
            'application_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', new Money, 'gt:0'],
        ];
    }
}
