<?php

namespace App\Actions\Receivables;

use App\Enums\CustomerReceiptStatus;
use App\Models\CustomerReceipt;
use App\Support\DecimalMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates a draft customer receipt with its invoice allocations.
 *
 * The one implementation behind both the website and the API. Input is expected to have
 * passed StoreCustomerReceiptRequest (full allocation, configured cash/bank account). A draft
 * moves no money; PostCustomerReceipt re-checks everything under lock. Receipts have no edit
 * step — a wrong draft is cancelled.
 */
class SaveCustomerReceipt
{
    public function __construct(private readonly GenerateReceivableReference $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?int $userId = null): CustomerReceipt
    {
        return DB::transaction(function () use ($data, $userId): CustomerReceipt {
            $receipt = CustomerReceipt::create([
                'receipt_number' => $this->references->forReceipt(Carbon::parse($data['receipt_date'])),
                'customer_id' => $data['customer_id'],
                'receipt_date' => $data['receipt_date'],
                'receipt_account_id' => $data['receipt_account_id'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'amount' => DecimalMath::cost(DecimalMath::of((string) $data['amount'])),
                'status' => CustomerReceiptStatus::Draft,
                'created_by' => $userId ?? auth()->id(),
            ]);

            foreach ($data['allocations'] as $allocation) {
                $receipt->allocations()->create([
                    'sales_invoice_id' => $allocation['sales_invoice_id'],
                    'amount' => DecimalMath::cost(DecimalMath::of((string) $allocation['amount'])),
                ]);
            }

            return $receipt->refresh();
        });
    }
}
