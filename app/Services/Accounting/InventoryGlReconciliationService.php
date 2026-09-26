<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountingSetting;
use App\Support\DecimalMath;

/**
 * Does the inventory ledger agree with the general ledger?
 *
 * Two independent records of the same thing:
 *
 *   inventory ledger   stock moved, so value moved   ← authoritative for what is on hand
 *   general ledger     a journal was posted          ← authoritative for the accounts
 *
 * After accounting-integrated operations they must agree to the last of six decimals, because
 * every stock movement writes its journal inside the same database transaction. A difference
 * is a real finding, and this report **never hides or corrects one**.
 *
 * ## Why a difference can be legitimate
 *
 * Stock that moved **before the accounting cutover** raised no journal at all. It is real
 * inventory with real value and no general-ledger counterpart, so the difference is expected
 * and correct — the books simply have not been opened on it yet. The same is true while
 * `operational_accounting_start_date` is NULL and nothing journals.
 *
 * The remedy is an opening-balance process at cutover, which is **not built**. Journals are
 * never backfilled automatically: inventing history to make a report look tidy destroys the
 * one thing an audit trail is for. See docs/ACCOUNTING_RULES.md §26.
 */
class InventoryGlReconciliationService
{
    public function __construct(
        private readonly AccountingBalanceService $balances,
        private readonly InventoryValuationReportService $valuation,
    ) {}

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     inventory_total: string,
     *     gl_total: string,
     *     difference_total: string,
     *     reconciled: bool,
     *     unmapped_value: string,
     *     unmapped_products: list<string>,
     *     cutover_date: string|null,
     *     accounting_enabled: bool
     * }
     */
    public function report(): array
    {
        $grouped = $this->valuation->valueByInventoryAccount();

        // Every account any product maps to, plus any account already carrying a general
        // ledger balance — so an account holding value for stock that has all gone still
        // shows its difference rather than vanishing from the report.
        $accountIds = array_keys($grouped['by_account']);
        $accounts = Account::query()
            ->whereIn('id', $accountIds)
            ->orWhereHas('mappedProducts')
            ->orderBy('code')
            ->get()
            ->unique('id');

        $rows = [];
        $inventoryTotal = DecimalMath::zero();
        $glTotal = DecimalMath::zero();
        $differenceTotal = DecimalMath::zero();

        foreach ($accounts as $account) {
            $inventoryValue = $grouped['by_account'][$account->id]['value'] ?? DecimalMath::cost('0');
            $glBalance = $this->balances->netBalance($account->id);
            $difference = DecimalMath::cost(DecimalMath::sub($inventoryValue, $glBalance));

            if (DecimalMath::isZero($inventoryValue) && DecimalMath::isZero($glBalance)) {
                continue;
            }

            $rows[] = [
                'account_id' => $account->id,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'products' => $grouped['by_account'][$account->id]['products'] ?? [],
                'inventory_value' => $inventoryValue,
                'gl_balance' => $glBalance,
                'difference' => $difference,
                'status' => DecimalMath::isZero($difference) ? 'MATCHED' : 'DIFFERENCE',
            ];

            $inventoryTotal = DecimalMath::add($inventoryTotal, $inventoryValue);
            $glTotal = DecimalMath::add($glTotal, $glBalance);
            $differenceTotal = DecimalMath::add($differenceTotal, $difference);
        }

        $settings = AccountingSetting::current();
        $differenceTotal = DecimalMath::cost($differenceTotal);

        return [
            'rows' => $rows,
            'inventory_total' => DecimalMath::cost($inventoryTotal),
            'gl_total' => DecimalMath::cost($glTotal),
            'difference_total' => $differenceTotal,
            'reconciled' => DecimalMath::isZero($differenceTotal)
                && DecimalMath::isZero($grouped['unmapped_value']),
            // Stock held by products with no mapping at all — real value, no ledger account.
            'unmapped_value' => $grouped['unmapped_value'],
            'unmapped_products' => $grouped['unmapped_products'],
            // The context a reader needs to judge whether a difference is a defect.
            'cutover_date' => $settings?->operational_accounting_start_date?->toDateString(),
            'accounting_enabled' => $settings?->isOperationalAccountingEnabled() ?? false,
        ];
    }
}
