<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Services\Accounting\AccountingBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The trial balance — read only.
 *
 * Because every posted entry balances, the two column totals must be identical. That
 * identity is the report's entire purpose: a difference means something is wrong that no
 * amount of presentation can hide, so the screen says so plainly rather than quietly
 * showing two numbers.
 */
class TrialBalanceController extends Controller
{
    public function __construct(private readonly AccountingBalanceService $balances) {}

    public function index(Request $request): Response
    {
        $periodId = $request->integer('fiscal_period_id') ?: null;
        $asOf = $request->date('as_of');

        $period = $periodId === null ? null : FiscalPeriod::find($periodId);

        $report = $this->balances->trialBalance(
            $asOf === null ? null : Carbon::parse($asOf),
            $period,
        );

        return Inertia::render('finance/trial-balance/Index', [
            'report' => $report,
            'periods' => FiscalPeriod::query()->orderByDesc('start_date')->get(['id', 'name']),
            'filters' => [
                'fiscal_period_id' => $periodId,
                'as_of' => $asOf?->toDateString(),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
