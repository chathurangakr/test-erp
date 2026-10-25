<?php

namespace App\Actions\Payables;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Enums\AdvanceStatus;
use App\Enums\PaymentMethod;
use App\Models\CashBankAccount;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceRefund;
use App\Services\Accounting\AccountingPolicy;
use App\Services\Accounting\AdvanceBalances;
use App\Support\DecimalMath;
use App\Support\DisplayNumber;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records part of a supplier advance returned to us by the supplier — never more than is left of it, never dated before it —
 * with its journal (RecordOperationalJournal::forSupplierAdvanceRefund). Corrected by ReverseSupplierAdvanceRefund.
 * See docs/ACCOUNTING_RULES.md §34.
 */
class RefundSupplierAdvance
{
    public function __construct(
        private readonly RecordOperationalJournal $accounting,
        private readonly AccountingPolicy $policy,
        private readonly AdvanceBalances $balances,
    ) {}

    /**
     * @param  array{refund_date: string, amount: string, cash_bank_account_id: int, payment_method: string, reference?: string|null}  $data
     */
    public function handle(SupplierAdvance $advance, array $data, ?int $userId = null): SupplierAdvanceRefund
    {
        return DB::transaction(function () use ($advance, $data, $userId): SupplierAdvanceRefund {
            $locked = SupplierAdvance::query()->whereKey($advance->getKey())->lockForUpdate()->firstOrFail();
            $date = CarbonImmutable::parse($data['refund_date'])->startOfDay();
            $amount = DecimalMath::money(DecimalMath::of((string) $data['amount']));

            if ($locked->status !== AdvanceStatus::Posted) {
                throw ValidationException::withMessages(['status' => __('Advance :reference has been reversed.', ['reference' => $locked->advance_number])]);
            }

            if ($date->lessThan(CarbonImmutable::parse($locked->advance_date))) {
                throw ValidationException::withMessages(['refund_date' => __('Date it on or after the advance.')]);
            }

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages(['amount' => __('Enter an amount above zero.')]);
            }

            $remaining = $this->balances->remaining($locked);

            if (DecimalMath::compare($amount, $remaining, 2) > 0) {
                throw ValidationException::withMessages(['amount' => __('Only :remaining is left of this advance.', ['remaining' => DisplayNumber::money($remaining)])]);
            }

            $method = PaymentMethod::from($data['payment_method']);
            $configuration = CashBankAccount::query()->with('account')->findOrFail($data['cash_bank_account_id']);
            $this->policy->assertUsableCashBankAccount($configuration, 'cash_bank_account_id', __('a supplier advance refund'));
            $this->policy->assertMethodFitsAccount($method, $configuration, 'cash_bank_account_id');

            $refund = SupplierAdvanceRefund::create([
                'supplier_advance_id' => $locked->id,
                'refund_date' => $date->toDateString(),
                'amount' => $amount,
                'cash_bank_account_id' => $configuration->id,
                'payment_method' => $method,
                'reference' => $data['reference'] ?? null,
                'status' => AdvanceStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $journal = $this->accounting->forSupplierAdvanceRefund($refund->load(['advance', 'cashBankAccount']), $userId);
            $refund->update(['journal_entry_id' => $journal->id]);

            return $refund->refresh();
        });
    }
}
