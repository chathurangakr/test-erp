<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\AccountingSetting;
use App\Support\DecimalMath;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A subsidiary report's total set beside its general-ledger control account.
 *
 * The AR aging, AP aging and GRNI reconciliation each assert an invariant — the report total
 * must equal the control account's balance exactly. This is that comparison, written once so
 * the web screens and the API report the same verdict. It compares; it never corrects.
 */
class ControlAccountReconciliation
{
    public function __construct(private readonly AccountingBalanceService $balances) {}

    /**
     * @return array{code: string, name: string, balance: string, reconciles: bool}|null
     */
    public function tradeReceivables(string $reportTotal, ?CarbonInterface $asOf = null): ?array
    {
        return $this->compare(AccountingSetting::current()?->tradeReceivablesAccount, $reportTotal, $asOf);
    }

    /**
     * @return array{code: string, name: string, balance: string, reconciles: bool}|null
     */
    public function tradePayables(string $reportTotal, ?CarbonInterface $asOf = null): ?array
    {
        return $this->compare(AccountingSetting::current()?->tradePayablesAccount, $reportTotal, $asOf);
    }

    /**
     * @return array{code: string, name: string, balance: string, reconciles: bool}|null
     */
    public function grni(string $reportTotal): ?array
    {
        return $this->compare(AccountingSetting::current()?->grniAccount, $reportTotal, null);
    }

    /**
     * @return array{code: string, name: string, balance: string, reconciles: bool}|null
     */
    private function compare(?Account $control, string $reportTotal, ?CarbonInterface $asOf): ?array
    {
        if ($control === null) {
            return null;
        }

        $balance = $this->balances->balance($control, $asOf === null ? null : Carbon::instance($asOf));

        return [
            'code' => $control->code,
            'name' => $control->name,
            'balance' => $balance,
            // They must agree; the report says so plainly rather than hiding a difference.
            'reconciles' => DecimalMath::compare($balance, $reportTotal) === 0,
        ];
    }
}
