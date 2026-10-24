<?php

namespace App\Actions\Assets;

use App\Actions\Payroll\WritePayrollJournal;
use App\Enums\FixedAssetStatus;
use App\Enums\PaymentMethod;
use App\Models\FixedAsset;
use App\Services\Accounting\AccountingPolicy;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sells an asset — or scraps it, at a price of 0 — in one step. With no depreciation (the
 * owner's choice) the profit or loss is simply sale price − purchase price:
 *
 *   Dr the cash / bank account   sale price (none when scrapped)
 *       Cr fixed assets          purchase price
 *       Cr gain on asset sales   sale − cost, when positive
 *   Dr loss on asset sales       cost − sale, when positive
 *
 * A loan the asset was bought on is not touched: it is still owed. See docs/FIXED_ASSETS.md.
 */
class SellFixedAsset
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly AssetMoney $money,
        private readonly WritePayrollJournal $journal,
    ) {}

    /**
     * @param  array{sold_date: string, sale_price: string, buyer?: string|null, cash_bank_account_id?: int|null, payment_method?: string|null}  $data
     */
    public function handle(FixedAsset $asset, array $data, ?int $userId = null): FixedAsset
    {
        return DB::transaction(function () use ($asset, $data, $userId): FixedAsset {
            $locked = FixedAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== FixedAssetStatus::InUse) {
                throw ValidationException::withMessages(['status' => __('Only an asset in use can be sold.')]);
            }

            $date = CarbonImmutable::parse($data['sold_date'])->startOfDay();
            $sale = DecimalMath::money(DecimalMath::of((string) $data['sale_price']));
            $cost = DecimalMath::money(DecimalMath::of((string) $locked->purchase_price));
            $difference = DecimalMath::money(DecimalMath::sub($sale, $cost));

            if (DecimalMath::isNegative($sale)) {
                throw ValidationException::withMessages(['sale_price' => __('The sale price cannot be negative.')]);
            }

            if ($date->lessThan(CarbonImmutable::parse($locked->purchase_date))) {
                throw ValidationException::withMessages(['sold_date' => __('An asset cannot be sold before it was bought.')]);
            }

            $account = DecimalMath::isPositive($sale) ? $this->money->account($data['cash_bank_account_id'] ?? null, $data['payment_method'] ?? null, __('an asset sale')) : null;
            $period = $this->money->period($date, 'sold_date');
            $zero = '0';

            $lines = [[$this->policy->fixedAssetsAccount()->id, $zero, $cost, $locked->name]];

            if ($account !== null) {
                $lines[] = [$account->account_id, $sale, $zero, $data['buyer'] ?? null];
            }

            if (DecimalMath::isPositive($difference)) {
                $lines[] = [$this->policy->assetGainAccount()->id, $zero, $difference, null];
            } elseif (DecimalMath::isNegative($difference)) {
                $lines[] = [$this->policy->assetLossAccount()->id, DecimalMath::money(DecimalMath::sub($cost, $sale)), $zero, null];
            }

            $journal = $this->journal->handle(
                $locked,
                $date,
                $period,
                DecimalMath::isPositive($sale)
                    ? RecordText::of('Asset :reference sold — :name', ['reference' => $locked->asset_number, 'name' => $locked->name])
                    : RecordText::of('Asset :reference scrapped — :name', ['reference' => $locked->asset_number, 'name' => $locked->name]),
                $lines,
                $userId,
            );

            $locked->update([
                'status' => FixedAssetStatus::Sold,
                'sold_date' => $date->toDateString(),
                'sale_price' => $sale,
                'buyer' => $data['buyer'] ?? null,
                'sale_cash_bank_account_id' => $account?->id,
                'sale_payment_method' => $account === null ? null : PaymentMethod::from((string) $data['payment_method']),
                'sale_journal_entry_id' => $journal->id,
            ]);

            return $locked->refresh();
        });
    }
}
