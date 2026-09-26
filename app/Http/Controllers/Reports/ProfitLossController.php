<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Services\Accounting\ProfitLossService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The profit and loss statement — read only.
 *
 * A fiscal period, when chosen, states its own window; the explicit dates are only used when
 * no period is selected.
 */
class ProfitLossController extends Controller
{
    public function __construct(private readonly ProfitLossService $profitLoss) {}

    public function index(Request $request): Response
    {
        $periodId = $request->integer('fiscal_period_id') ?: null;
        $period = $periodId === null ? null : FiscalPeriod::find($periodId);

        $from = $request->date('from');
        $to = $request->date('to');

        return Inertia::render('finance/reports/ProfitLoss', [
            'statement' => $this->profitLoss->statement(
                $from === null ? null : Carbon::parse($from),
                $to === null ? null : Carbon::parse($to),
                $period,
            ),
            'periods' => FiscalPeriod::query()->orderByDesc('start_date')->get(['id', 'name']),
            'filters' => [
                'fiscal_period_id' => $periodId,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
