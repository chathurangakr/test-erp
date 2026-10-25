<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Accounting\CashFlowService;
use App\Support\BusinessClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cash flow statement — read only. Defaults to this month so far. See
 * docs/ACCOUNTING_RULES.md §37.
 */
class CashFlowController extends Controller
{
    public function __construct(private readonly CashFlowService $cashFlow) {}

    public function index(Request $request): Response
    {
        [$from, $to] = self::window($request);

        return Inertia::render('finance/reports/CashFlow', [
            'statement' => $this->cashFlow->statement($from, $to),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'currency' => config('accounting.base_currency'),
        ]);
    }

    /**
     * The chosen dates, or this month so far.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function window(Request $request): array
    {
        $today = BusinessClock::today();
        $from = $request->date('from') ?? Carbon::parse($today->startOfMonth()->toDateString());
        $to = $request->date('to') ?? Carbon::parse($today->toDateString());

        return [Carbon::parse($from->toDateString()), Carbon::parse($to->toDateString())];
    }
}
