<?php

namespace App\Actions\Payables;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Enums\AdvanceStatus;
use App\Enums\PaymentMethod;
use App\Models\CashBankAccount;
use App\Models\Supplier;
use App\Models\SupplierAdvance;
use App\Services\Accounting\AccountingPolicy;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records money paid to a supplier BEFORE their invoice, in one step, with its journal
 * (RecordOperationalJournal::forSupplierAdvance). It is set against their invoices later
 * (ApplySupplierAdvance) and corrected by ReverseSupplierAdvance. See docs/ACCOUNTING_RULES.md §34.
 */
class RecordSupplierAdvance
{
    public function __construct(
        private readonly RecordOperationalJournal $accounting,
        private readonly AccountingPolicy $policy,
        private readonly GeneratePayableReference $references,
    ) {}

    /**
     * @param  array{supplier_id: int, advance_date: string, amount: string, cash_bank_account_id: int, payment_method: string, cheque_number?: string|null, cheque_date?: string|null, cheque_bank?: string|null, reference?: string|null, note?: string|null}  $data
     */
    public function handle(array $data, ?int $userId = null): SupplierAdvance
    {
        return DB::transaction(function () use ($data, $userId): SupplierAdvance {
            $party = Supplier::query()->whereKey($data['supplier_id'])->lockForUpdate()->firstOrFail();

            if (! $party->is_active) {
                throw ValidationException::withMessages(['supplier_id' => __('Advances can only be paid to an active supplier.')]);
            }

            $amount = DecimalMath::money(DecimalMath::of((string) $data['amount']));

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages(['amount' => __('Enter an amount above zero.')]);
            }

            $date = CarbonImmutable::parse($data['advance_date'])->startOfDay();
            $method = PaymentMethod::from($data['payment_method']);
            $configuration = CashBankAccount::query()->with('account')->findOrFail($data['cash_bank_account_id']);
            $this->policy->assertUsableCashBankAccount($configuration, 'cash_bank_account_id', __('a supplier advance'));
            $this->policy->assertMethodFitsAccount($method, $configuration, 'cash_bank_account_id');
            $isCheque = $method === PaymentMethod::Cheque;

            $advance = SupplierAdvance::create([
                'advance_number' => $this->references->forSupplierAdvance($date),
                'supplier_id' => $party->id,
                'advance_date' => $date->toDateString(),
                'amount' => $amount,
                'cash_bank_account_id' => $configuration->id,
                'payment_method' => $method,
                'cheque_number' => $isCheque ? ($data['cheque_number'] ?? null) : null,
                'cheque_date' => $isCheque ? ($data['cheque_date'] ?? null) : null,
                'cheque_bank' => $isCheque ? ($data['cheque_bank'] ?? null) : null,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => AdvanceStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $journal = $this->accounting->forSupplierAdvance($advance->load(['supplier', 'cashBankAccount']), $userId);
            $advance->update(['journal_entry_id' => $journal->id]);

            return $advance->refresh();
        });
    }
}
