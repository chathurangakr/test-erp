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
 * no period is selected. It may be shown beside the window before it or the same window a year
 * earlier.
 */
class ProfitLossController extends Controller
{
    /** What a statement may be compared with — see ProfitLossService::priorWindow(). */
    public const COMPARISONS = ['previous', 'last_year'];

    public function __construct(private readonly ProfitLossService $profitLoss) {}

    public function index(Request $request): Response
    {
        $periodId = $request->integer('fiscal_period_id') ?: null;
        $period = $periodId === null ? null : FiscalPeriod::find($periodId);

        $from = $request->date('from');
        $to = $request->date('to');
        $compare = in_array($request->query('compare'), self::COMPARISONS, true) ? $request->query('compare') : null;

        $statement = $this->profitLoss->statement(
            $from === null ? null : Carbon::parse($from),
            $to === null ? null : Carbon::parse($to),
            $period,
        );

        return Inertia::render('finance/reports/ProfitLoss', [
            'statement' => $statement,
            'comparison' => $this->comparison($statement, $compare),
            'periods' => FiscalPeriod::query()->orderByDesc('start_date')->get(['id', 'name']),
            'filters' => [
                'fiscal_period_id' => $periodId,
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'compare' => $compare,
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }

    /**
     * The statement beside an earlier window, when one is asked for and the window has both ends.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>|null
     */
    public function comparison(array $statement, ?string $compare): ?array
    {
        if ($compare === null || $statement['from'] === null || $statement['to'] === null) {
            return null;
        }

        $from = Carbon::parse($statement['from']);
        $to = Carbon::parse($statement['to']);
        [$priorFrom, $priorTo] = ProfitLossService::priorWindow($from, $to, $compare);

        return $this->profitLoss->compare($from, $to, $priorFrom, $priorTo);
    }
}
