<?php

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\CloseFinancialYear;
use App\Actions\Accounting\ReverseFinancialYearClosing;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\FinancialYearClosing;
use App\Models\FiscalPeriod;
use App\Services\Accounting\FinancialYears;
use App\Services\Accounting\ProfitLossService;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance → Year-end closing: each financial year with its profit, whether it is closed, and the
 * buttons to close it into retained earnings or undo the latest close. See
 * docs/ACCOUNTING_RULES.md §24.
 */
class YearEndClosingController extends Controller
{
    public function __construct(
        private readonly FinancialYears $years,
        private readonly ProfitLossService $profitLoss,
    ) {}

    public function index(): Response
    {
        $today = BusinessClock::today();
        $last = $this->years->lastClosing();

        $years = array_map(function (array $year) use ($today, $last): array {
            [$start, $end] = $year;
            $closing = $this->years->closingFor($end)?->load(['journalEntry:id,journal_number', 'createdBy:id,name']);
            $ended = $today->greaterThan($end);

            return [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'ended' => $ended,
                // The year's real profit — closing entries are never part of it.
                'net_profit' => $this->profitLoss->netProfit($start, $end),
                'last_period_open' => FiscalPeriod::openFor($end) !== null,
                'can_close' => $ended && $closing === null && ($last === null || $last->year_end_date->lessThan($end)),
                'closing' => $closing === null ? null : [
                    'id' => $closing->id,
                    'net_profit' => (string) $closing->net_profit,
                    'journal_id' => $closing->journal_entry_id,
                    'journal_number' => $closing->journalEntry?->journal_number,
                    'closed_by' => $closing->createdBy?->name,
                    'closed_at' => BusinessClock::display($closing->posted_at),
                    'can_reverse' => $last?->is($closing) ?? false,
                ],
            ];
        }, $this->years->years());

        return Inertia::render('finance/year-end/Index', [
            'years' => $years,
            'endMonth' => CarbonImmutable::create(2000, $this->years->endMonth(), 1)->format('F'),
            'retainedEarnings' => Account::query()->where('code', (string) config('accounting.retained_earnings_code', '3200'))->first(['code', 'name']),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(Request $request, CloseFinancialYear $action): RedirectResponse
    {
        $data = $request->validate(['year_end_date' => ['required', 'date_format:Y-m-d']]);
        $closing = $action->handle($data['year_end_date'], $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The financial year ending :date is closed.', ['date' => $closing->year_end_date->toDateString()])]);

        return to_route('year-end.index');
    }

    public function reverse(Request $request, FinancialYearClosing $closing, ReverseFinancialYearClosing $action): RedirectResponse
    {
        $action->handle($closing, $request->user()->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('The year-end close was reversed. The year is open again.')]);

        return to_route('year-end.index');
    }
}
