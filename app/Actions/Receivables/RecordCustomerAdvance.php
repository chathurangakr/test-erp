<?php

namespace App\Actions\Receivables;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Enums\AdvanceStatus;
use App\Enums\PaymentMethod;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\CustomerAdvance;
use App\Services\Accounting\AccountingPolicy;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records money received from a customer BEFORE their invoice, in one step, with its journal
 * (RecordOperationalJournal::forCustomerAdvance). It is set against their invoices later
 * (ApplyCustomerAdvance) and corrected by ReverseCustomerAdvance. See docs/ACCOUNTING_RULES.md §34.
 */
class RecordCustomerAdvance
{
    public function __construct(
        private readonly RecordOperationalJournal $accounting,
        private readonly AccountingPolicy $policy,
        private readonly GenerateReceivableReference $references,
    ) {}

    /**
     * @param  array{customer_id: int, advance_date: string, amount: string, cash_bank_account_id: int, payment_method: string, cheque_number?: string|null, cheque_date?: string|null, cheque_bank?: string|null, reference?: string|null, note?: string|null}  $data
     */
    public function handle(array $data, ?int $userId = null): CustomerAdvance
    {
        return DB::transaction(function () use ($data, $userId): CustomerAdvance {
            $party = Customer::query()->whereKey($data['customer_id'])->lockForUpdate()->firstOrFail();

            if (! $party->is_active) {
                throw ValidationException::withMessages(['customer_id' => __('Advances can only be received from an active customer.')]);
            }

            $amount = DecimalMath::money(DecimalMath::of((string) $data['amount']));

            if (! DecimalMath::isPositive($amount)) {
                throw ValidationException::withMessages(['amount' => __('Enter an amount above zero.')]);
            }

            $date = CarbonImmutable::parse($data['advance_date'])->startOfDay();
            $method = PaymentMethod::from($data['payment_method']);
            $configuration = CashBankAccount::query()->with('account')->findOrFail($data['cash_bank_account_id']);
            $this->policy->assertUsableCashBankAccount($configuration, 'cash_bank_account_id', __('a customer advance'));
            $this->policy->assertMethodFitsAccount($method, $configuration, 'cash_bank_account_id');
            $isCheque = $method === PaymentMethod::Cheque;

            $advance = CustomerAdvance::create([
                'advance_number' => $this->references->forCustomerAdvance($date),
                'customer_id' => $party->id,
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

            $journal = $this->accounting->forCustomerAdvance($advance->load(['customer', 'cashBankAccount']), $userId);
            $advance->update(['journal_entry_id' => $journal->id]);

            return $advance->refresh();
        });
    }
}
