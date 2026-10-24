<?php

namespace App\Actions\Assets;

use App\Actions\Payroll\WritePayrollJournal;
use App\Enums\FixedAssetStatus;
use App\Enums\LoanDocumentStatus;
use App\Enums\PaymentMethod;
use App\Models\AssetMaintenance;
use App\Models\FixedAsset;
use App\Services\Accounting\AccountingPolicy;
use App\Support\DecimalMath;
use App\Support\RecordText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records work done on an asset — a service or a repair — and what it cost:
 * `Dr repairs and maintenance / Cr the cash or bank account`. A free job (cost 0) is recorded
 * for the history only, with no journal. See docs/FIXED_ASSETS.md.
 */
class RecordAssetMaintenance
{
    public function __construct(
        private readonly AccountingPolicy $policy,
        private readonly AssetMoney $money,
        private readonly GenerateAssetReference $references,
        private readonly WritePayrollJournal $journal,
    ) {}

    /**
     * @param  array{maintenance_date: string, work_done: string, done_by?: string|null, cost: string, cash_bank_account_id?: int|null, payment_method?: string|null, reference?: string|null}  $data
     */
    public function handle(FixedAsset $asset, array $data, ?int $userId = null): AssetMaintenance
    {
        return DB::transaction(function () use ($asset, $data, $userId): AssetMaintenance {
            $locked = FixedAsset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== FixedAssetStatus::InUse) {
                throw ValidationException::withMessages(['status' => __('Maintenance can only be recorded on an asset in use.')]);
            }

            $date = CarbonImmutable::parse($data['maintenance_date'])->startOfDay();
            $cost = DecimalMath::money(DecimalMath::of((string) $data['cost']));

            if (DecimalMath::isNegative($cost)) {
                throw ValidationException::withMessages(['cost' => __('The cost cannot be negative.')]);
            }

            $account = DecimalMath::isPositive($cost) ? $this->money->account($data['cash_bank_account_id'] ?? null, $data['payment_method'] ?? null, __('asset maintenance')) : null;
            $period = $account === null ? null : $this->money->period($date, 'maintenance_date');
            $expense = $account === null ? null : $this->policy->maintenanceAccount();

            $maintenance = AssetMaintenance::create([
                'maintenance_number' => $this->references->forMaintenance($date),
                'fixed_asset_id' => $locked->id,
                'maintenance_date' => $date->toDateString(),
                'work_done' => $data['work_done'],
                'done_by' => $data['done_by'] ?? null,
                'cost' => $cost,
                'cash_bank_account_id' => $account?->id,
                'payment_method' => $account === null ? null : PaymentMethod::from((string) $data['payment_method']),
                'reference' => $data['reference'] ?? null,
                'status' => LoanDocumentStatus::Posted,
                'created_by' => $userId,
                'posted_at' => now(),
            ]);

            if ($account !== null) {
                $journal = $this->journal->handle(
                    $maintenance,
                    $date,
                    $period,
                    RecordText::of('Maintenance :reference on :asset — :work', [
                        'reference' => $maintenance->maintenance_number,
                        'asset' => $locked->name,
                        'work' => $maintenance->work_done,
                    ]),
                    [
                        [$expense->id, $cost, '0', $locked->name],
                        [$account->account_id, '0', $cost, $data['reference'] ?? null],
                    ],
                    $userId,
                );
                $maintenance->update(['journal_entry_id' => $journal->id]);
            }

            return $maintenance->refresh();
        });
    }
}
