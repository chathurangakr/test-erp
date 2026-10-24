<?php

namespace App\Http\Requests\Sales;

use App\Enums\PaymentMethod;
use App\Enums\SalesReturnSettlement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Goods a customer brought back. The credit and the costs are never sent — the server reads
 * them from the delivery line. Money back needs the account it leaves from; a credit or an
 * exchange names the invoice it reduces.
 */
class StoreSalesReturnRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $refund = $this->input('settlement') === SalesReturnSettlement::Refund->value;

        return [
            'return_date' => ['required', 'date'],
            'delivery_line_id' => ['required', 'integer', Rule::exists('delivery_lines', 'id')],
            'good_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
            'damaged_quantity' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'secondary_quantity' => ['nullable', 'numeric', 'gt:0', 'decimal:0,3'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('is_active', 1)->where('holds_customer_items', 0)],
            'settlement' => ['required', Rule::enum(SalesReturnSettlement::class)],
            'credit_invoice_id' => $refund ? ['prohibited'] : ['nullable', 'integer', Rule::exists('sales_invoices', 'id')],
            'cash_bank_account_id' => $refund ? ['required', 'integer', Rule::exists('cash_bank_accounts', 'id')->where('is_active', 1)] : ['prohibited'],
            'payment_method' => $refund ? ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::BankTransfer->value])] : ['prohibited'],
            'reference' => ['nullable', 'string', 'max:100'],
            'reason' => ['required', 'string', 'max:255'],
            // Never accepted: the credit follows the price charged on the delivery line.
            'credit_amount' => ['prohibited'],
        ];
    }
}
