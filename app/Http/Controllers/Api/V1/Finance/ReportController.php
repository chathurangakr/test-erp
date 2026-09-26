<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\CashBankAccount;
use App\Models\FiscalPeriod;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\CashBankBookService;
use App\Services\Accounting\ControlAccountReconciliation;
use App\Services\Accounting\FinanceDashboardService;
use App\Services\Accounting\GrniReconciliationService;
use App\Services\Accounting\InventoryGlReconciliationService;
use App\Services\Accounting\InventoryValuationReportService;
use App\Services\Accounting\ProductProfitabilityService;
use App\Services\Accounting\ProfitLossService;
use App\Services\Accounting\SalesBillingReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Finance reports over the API — read only.
 *
 * Each endpoint returns `{"data": ...}` holding exactly what the owning service returns, the
 * same service the web screen calls. Nothing is recalculated here, and every money figure is
 * already an exact decimal string. Filters are validated first, so a malformed date is a 422,
 * never a server error.
 */
class ReportController extends Controller
{
    public function financeDashboard(Request $request, FinanceDashboardService $dashboard): JsonResponse
    {
        $input = $request->validate($this->windowRules());
        $window = $dashboard->resolveWindow($input['fiscal_period_id'] ?? null, $request->date('from'), $request->date('to'));

        return $this->data($dashboard->summary($window['from'], $window['to'], $window['period']));
    }

    public function profitLoss(Request $request, ProfitLossService $profitLoss): JsonResponse
    {
        $input = $request->validate($this->windowRules());
        $period = isset($input['fiscal_period_id']) ? FiscalPeriod::find($input['fiscal_period_id']) : null;

        return $this->data($profitLoss->statement($request->date('from'), $request->date('to'), $period));
    }

    public function balanceSheet(Request $request, BalanceSheetService $balanceSheet): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = $this->asOf($request);

        return $this->data($balanceSheet->statement($asOf) + [
            // The ledger's real retained earnings, beside the computed Current Earnings.
            'retained_earnings' => $balanceSheet->retainedEarnings($asOf),
        ]);
    }

    public function inventoryValuation(Request $request, InventoryValuationReportService $valuation): JsonResponse
    {
        $input = $request->validate([
            'product_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        return $this->data($valuation->report($input['product_id'] ?? null, $input['warehouse_id'] ?? null));
    }

    public function inventoryGlReconciliation(InventoryGlReconciliationService $reconciliation): JsonResponse
    {
        return $this->data($reconciliation->report());
    }

    public function cashBankPosition(Request $request, CashBankBookService $cashBank): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date'], 'show_inactive' => ['nullable', 'boolean']]);

        return $this->data($cashBank->summary($this->asOf($request), activeOnly: ! $request->boolean('show_inactive')));
    }

    public function productProfitability(Request $request, ProductProfitabilityService $profitability): JsonResponse
    {
        $input = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'product_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
        ]);

        $from = $request->date('from');
        $to = $request->date('to');
        $productId = $input['product_id'] ?? null;
        $customerId = $input['customer_id'] ?? null;

        $report = $profitability->report($from, $to, $productId, $customerId);

        if ($productId !== null) {
            // The deliveries behind one product's figures — the web screen's drill-down.
            $report['deliveries'] = $profitability->deliveriesFor($productId, $from, $to, $customerId);
        }

        return $this->data($report);
    }

    public function arAging(Request $request, AccountsReceivableService $receivables, ControlAccountReconciliation $control): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = $this->asOf($request);
        $report = $receivables->aging($asOf);

        return $this->data($report + ['control' => $control->tradeReceivables($report['totals']['total'], $asOf)]);
    }

    public function apAging(Request $request, AccountsPayableService $payables, ControlAccountReconciliation $control): JsonResponse
    {
        $request->validate(['as_of' => ['nullable', 'date']]);
        $asOf = $this->asOf($request);
        $report = $payables->aging($asOf);

        return $this->data($report + ['control' => $control->tradePayables($report['totals']['total'], $asOf)]);
    }

    public function grniReconciliation(GrniReconciliationService $grni, ControlAccountReconciliation $control): JsonResponse
    {
        $report = $grni->report();

        return $this->data($report + ['control' => $control->grni($report['remaining_total'])]);
    }

    public function unbilledDeliveries(SalesBillingReconciliationService $billing): JsonResponse
    {
        return $this->data($billing->report());
    }

    /**
     * Every posted movement through one configured cash or bank account, and proof that its
     * closing balance equals the general ledger. Unlike the web screen, the account is required
     * rather than defaulted — an API should not guess which account was meant.
     */
    public function cashBankBook(Request $request, CashBankBookService $book, AccountingBalanceService $balances): JsonResponse
    {
        $input = $request->validate([
            'cash_bank_account_id' => ['required', 'integer', 'exists:cash_bank_accounts,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $account = CashBankAccount::query()->with('account:id,code,name')->findOrFail($input['cash_bank_account_id']);
        $from = $request->date('from');
        $to = $request->date('to');

        return $this->data([
            'account' => [
                'id' => $account->id,
                'display_name' => $account->display_name,
                'kind' => $account->account_kind->value,
                'account_code' => $account->account?->code,
                'account_name' => $account->account?->name,
            ],
            'book' => $book->book($account, $from, $to),
            'ledger' => [
                'balance' => $balances->netBalance($account->account_id, $to === null ? null : Carbon::instance($to)),
                'reconciles' => $book->reconciles($account, $to),
            ],
        ]);
    }

    /**
     * @return array<string, list<string>>
     */
    private function windowRules(): array
    {
        return [
            'fiscal_period_id' => ['nullable', 'integer', 'exists:fiscal_periods,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }

    private function asOf(Request $request): ?Carbon
    {
        $asOf = $request->date('as_of');

        return $asOf === null ? null : Carbon::instance($asOf);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function data(array $payload): JsonResponse
    {
        return response()->json(['data' => $payload]);
    }
}
