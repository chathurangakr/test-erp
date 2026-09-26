<?php

namespace App\Actions\Payables;

use App\Enums\SupplierPaymentStatus;
use App\Models\SupplierPayment;
use App\Support\DecimalMath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Creates a draft supplier payment with its invoice allocations.
 *
 * The one implementation behind both the website and the API. Input is expected to have
 * passed StoreSupplierPaymentRequest (full allocation, configured cash/bank account). A draft
 * moves no money; PostSupplierPayment re-checks everything under lock. Payments have no edit
 * step — a wrong draft is cancelled.
 */
class SaveSupplierPayment
{
    public function __construct(private readonly GeneratePayableReference $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?int $userId = null): SupplierPayment
    {
        return DB::transaction(function () use ($data, $userId): SupplierPayment {
            $payment = SupplierPayment::create([
                'payment_number' => $this->references->forPayment(Carbon::parse($data['payment_date'])),
                'supplier_id' => $data['supplier_id'],
                'payment_date' => $data['payment_date'],
                'payment_account_id' => $data['payment_account_id'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'amount' => DecimalMath::cost(DecimalMath::of((string) $data['amount'])),
                'status' => SupplierPaymentStatus::Draft,
                'created_by' => $userId ?? auth()->id(),
            ]);

            foreach ($data['allocations'] as $allocation) {
                $payment->allocations()->create([
                    'supplier_invoice_id' => $allocation['supplier_invoice_id'],
                    'amount' => DecimalMath::cost(DecimalMath::of((string) $allocation['amount'])),
                ]);
            }

            return $payment->refresh();
        });
    }
}
