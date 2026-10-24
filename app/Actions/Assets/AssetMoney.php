<?php

namespace App\Actions\Assets;

use App\Enums\PaymentMethod;
use App\Models\CashBankAccount;
use App\Models\FiscalPeriod;
use App\Services\Accounting\AccountingPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The checks every asset document shares: a configured cash/bank account with a fitting payment
 * method, and an open fiscal period for its date.
 */
class AssetMoney
{
    public function __construct(private readonly AccountingPolicy $policy) {}

    public function account(?int $cashBankAccountId, ?string $method, string $subject): CashBankAccount
    {
        $configuration = CashBankAccount::query()->with('account')->find($cashBankAccountId ?? 0)
            ?? throw ValidationException::withMessages(['cash_bank_account_id' => __('Choose the cash or bank account.')]);
        $this->policy->assertUsableCashBankAccount($configuration, 'cash_bank_account_id', $subject);
        $this->policy->assertMethodFitsAccount(PaymentMethod::from((string) $method), $configuration, 'cash_bank_account_id');

        return $configuration;
    }

    public function period(CarbonImmutable $date, string $field): FiscalPeriod
    {
        return FiscalPeriod::openFor($date) ?? throw ValidationException::withMessages([
            $field => __('There is no open fiscal period covering :date. Open the period first.', ['date' => $date->toDateString()]),
        ]);
    }
}
