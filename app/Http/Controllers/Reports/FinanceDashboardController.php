<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Services\Accounting\FinanceDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        $default = $this->dashboard->defaultWindow();

        $periodId = $request->integer('fiscal_period_id') ?: null;
        $period = $periodId === null ? null : FiscalPeriod::find($periodId);

        $from = $request->date('from') ?? ($period === null ? Carbon::parse($default['from']) : null);
        $to = $request->date('to') ?? ($period === null ? Carbon::parse($default['to']) : null);

        return Inertia::render('finance/Dashboard', [
            'summary' => $this->dashboard->summary(
                $from === null ? null : Carbon::parse($from),
                $to === null ? null : Carbon::parse($to),
                $period,
            ),
            'periods' => FiscalPeriod::query()->orderByDesc('start_date')->get(['id', 'name']),
            'filters' => [
                'fiscal_period_id' => $periodId,
                'from' => $from === null ? null : Carbon::parse($from)->toDateString(),
                'to' => $to === null ? null : Carbon::parse($to)->toDateString(),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
