<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Services\Accounting\FinanceDashboardService;
use App\Support\BusinessClock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The management dashboard — read only.
 *
 * The screen opens on a convenient window, but the period is always sent explicitly: the
 * service never assumes "this month", because an accounting period is a business fact.
 */
class FinanceDashboardController extends Controller
{
    public function __construct(private readonly FinanceDashboardService $dashboard) {}

    public function index(Request $request): Response
    {
        $periodId = $request->integer('fiscal_period_id') ?: null;
        $window = $this->dashboard->resolveWindow($periodId, $request->date('from'), $request->date('to'));

        return Inertia::render('finance/Dashboard', [
            'summary' => $this->dashboard->summary($window['from'], $window['to'], $window['period']),
            // Six months up to the window's end, for the trend chart (web only).
            'trend' => $this->dashboard->monthlyTrend($window['period']?->end_date ?? $window['to'] ?? BusinessClock::today()),
            'periods' => FiscalPeriod::query()->orderByDesc('start_date')->get(['id', 'name']),
            'filters' => [
                'fiscal_period_id' => $periodId,
                'from' => $window['from']?->toDateString(),
                'to' => $window['to']?->toDateString(),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
