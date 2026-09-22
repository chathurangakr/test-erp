<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Accounting\AccountingBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The general ledger for one account — read only.
 *
 * The running balance is calculated per request and never stored. Anything before the chosen
 * window is folded into an opening balance, so the figures shown are true rather than
 * starting from an arbitrary zero.
 */
class GeneralLedgerController extends Controller
{
    public function __construct(private readonly AccountingBalanceService $balances) {}

    public function index(Request $request): Response
    {
        $accountId = $request->integer('account_id') ?: null;
        $from = $request->date('from');
        $to = $request->date('to');

        $account = $accountId === null ? null : Account::find($accountId);
        $ledger = null;

        if ($account !== null) {
            $ledger = $this->balances->generalLedger(
                $account,
                $from === null ? null : Carbon::parse($from),
                $to === null ? null : Carbon::parse($to),
            );
        }

        return Inertia::render('finance/general-ledger/Index', [
            'accounts' => Account::query()
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $a): array => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name]),
            'account' => $account === null ? null : [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type_label' => $account->account_type->label(),
                'normal_balance_label' => $account->normal_balance->label(),
            ],
            'ledger' => $ledger,
            'filters' => [
                'account_id' => $accountId,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
