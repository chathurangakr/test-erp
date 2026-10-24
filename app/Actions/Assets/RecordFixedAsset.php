<?php

namespace App\Actions\Assets;

use App\Actions\Loans\GenerateLoanReference;
use App\Actions\Payroll\WritePayrollJournal;
use App\Enums\AssetCategory;
use App\Enums\AssetFunding;
use App\Enums\FixedAssetStatus;
use App\Enums\LoanDocumentStatus;
use App\Enums\LoanFunding;
use App\Enums\PaymentMethod;
use App\Models\FixedAsset;
use App\Models\Loan;
use App\Services\Accounting\AccountingPolicy;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records an asset the factory bought or already owns, at what it cost, in one step:
 *
 *   CASH_BANK  Dr fixed assets / Cr the cash or bank account
 *   LOAN       Dr fixed assets / Cr the cash or bank account (down payment, if any)
 *                              / Cr loans payable (the rest — a loan is opened for it)
 *   EXISTING   Dr fixed assets / Cr opening balance equity
 *
 * An asset bought on a loan opens that loan (funding ASSET) with the lender, installment and due
 * day, so its installments and reminders work like any other loan; the asset's journal is the
 * loan's journal. See docs/FIXED_ASSETS.md.
 */
class RecordFixedAsset
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly AssetMoney $money,
        private readonly GenerateAssetReference $references,
        private readonly GenerateLoanReference $loanReferences,
        private readonly WritePayrollJournal $journal,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated by StoreFixedAssetRequest
     */
    public function handle(array $data, ?int $userId = null): FixedAsset
    {
        return DB::transaction(function () use ($data, $userId): FixedAsset {
            $date = CarbonImmutable::parse($data['purchase_date'])->startOfDay();
            $funding = AssetFunding::from($data['funding']);
            $price = DecimalMath::money(DecimalMath::of((string) $data['purchase_price']));
            $down = $funding === AssetFunding::Loan ? DecimalMath::money(DecimalMath::of((string) ($data['down_payment'] ?? '0'))) : DecimalMath::money('0');
            $borrowed = DecimalMath::money(DecimalMath::sub($price, $down));

            if (! DecimalMath::isPositive($price)) {
                throw ValidationException::withMessages(['purchase_price' => __('The price must be greater than zero.')]);
            }

            if ($funding === AssetFunding::Loan && (DecimalMath::isNegative($down) || ! DecimalMath::isPositive($borrowed))) {
                throw ValidationException::withMessages(['down_payment' => __('The down payment must be less than the price; the rest is the loan.')]);
            }

            $fixedAssets = $this->policy->fixedAssetsAccount();
            $paysNow = $funding === AssetFunding::CashBank || DecimalMath::isPositive($down);
            $account = $paysNow ? $this->money->account($data['cash_bank_account_id'] ?? null, $data['payment_method'] ?? null, __('an asset')) : null;
            $period = $this->money->period($date, 'purchase_date');

            $asset = FixedAsset::create([
                'asset_number' => $this->references->forAsset($date),
                'name' => $data['name'],
                'category' => AssetCategory::from($data['category']),
                'description' => $data['description'] ?? null,
                'purchase_date' => $date->toDateString(),
                'purchase_price' => $price,
                'funding' => $funding,
                'down_payment' => $down,
                'cash_bank_account_id' => $account?->id,
                'payment_method' => $account === null ? null : PaymentMethod::from($data['payment_method']),
                'status' => FixedAssetStatus::InUse,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            $loan = $funding === AssetFunding::Loan ? $this->openLoan($asset, $data, $borrowed, $date, $userId) : null;
            $credits = match ($funding) {
                AssetFunding::CashBank => [[$account->account_id, '0', $price, $data['reference'] ?? null]],
                AssetFunding::Existing => [[$this->policy->openingBalanceAccount()->id, '0', $price, null]],
                AssetFunding::Loan => [
                    [$account?->account_id ?? $fixedAssets->id, '0', $down, RecordText::of('Down payment')],
                    [$this->policy->loansAccount()->id, '0', $borrowed, $loan->lender],
                ],
            };

            $journal = $this->journal->handle(
                $asset,
                $date,
                $period,
                RecordText::of('Asset :reference — :name', ['reference' => $asset->asset_number, 'name' => $asset->name]),
                [[$fixedAssets->id, $price, '0', $asset->name], ...$credits],
                $userId,
            );

            $asset->update(['journal_entry_id' => $journal->id, 'loan_id' => $loan?->id]);
            $loan?->update(['journal_entry_id' => $journal->id]);

            return $asset->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function openLoan(FixedAsset $asset, array $data, string $borrowed, CarbonImmutable $date, ?int $userId): Loan
    {
        if (blank($data['lender'] ?? null)) {
            throw ValidationException::withMessages(['lender' => __('Enter who lent the money for this asset.')]);
        }

        return Loan::create([
            'loan_number' => $this->loanReferences->forLoan($date),
            'lender' => $data['lender'],
            'purpose' => $asset->name,
            'start_date' => $date->toDateString(),
            'funding' => LoanFunding::Asset,
            'amount' => $borrowed,
            'fixed_asset_id' => $asset->id,
            'has_interest' => (bool) ($data['has_interest'] ?? true),
            'installment_amount' => filled($data['installment_amount'] ?? null) ? DecimalMath::money(DecimalMath::of((string) $data['installment_amount'])) : null,
            'due_day' => $data['due_day'] ?? null,
            'status' => LoanDocumentStatus::Posted,
            'created_by' => $userId,
            'posted_at' => now(),
        ]);
    }
}
