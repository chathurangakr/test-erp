<?php

namespace Database\Factories;

use App\Enums\SupplierInvoiceLineType;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierInvoiceLine>
 */
class SupplierInvoiceLineFactory extends Factory
{
    protected $model = SupplierInvoiceLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'supplier_invoice_id' => SupplierInvoice::factory(),
            'line_type' => SupplierInvoiceLineType::DirectExpense,
            'goods_receipt_id' => null,
            'debit_account_id' => null,
            'description' => null,
            'amount' => '1000.000000',
        ];
    }

    public function grniMatch(int $goodsReceiptId, string $amount): static
    {
        return $this->state(fn (): array => [
            'line_type' => SupplierInvoiceLineType::GrniMatch,
            'goods_receipt_id' => $goodsReceiptId,
            'debit_account_id' => null,
            'amount' => $amount,
        ]);
    }

    public function directExpense(int $accountId, string $amount): static
    {
        return $this->state(fn (): array => [
            'line_type' => SupplierInvoiceLineType::DirectExpense,
            'goods_receipt_id' => null,
            'debit_account_id' => $accountId,
            'amount' => $amount,
        ]);
    }
}
