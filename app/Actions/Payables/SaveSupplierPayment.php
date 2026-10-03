<?php

namespace App\Actions\Payables;

use App\Enums\PaymentMethod;
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
                ...$this->paymentDetails($data),
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

    /**
     * The method, and a cheque's details only when it IS a cheque — details typed for cash or
     * a transfer would describe a cheque that does not exist.
     *
     * @param  array<string, mixed>  $data
     * @return array{payment_method: PaymentMethod, cheque_number: string|null, cheque_date: string|null, cheque_bank: string|null}
     */
    private function paymentDetails(array $data): array
    {
        $method = PaymentMethod::from($data['payment_method']);
        $cheque = $method->requiresChequeDetails();

        return [
            'payment_method' => $method,
            'cheque_number' => $cheque ? ($data['cheque_number'] ?? null) : null,
            'cheque_date' => $cheque ? ($data['cheque_date'] ?? null) : null,
            'cheque_bank' => $cheque ? ($data['cheque_bank'] ?? null) : null,
        ];
    }
}
