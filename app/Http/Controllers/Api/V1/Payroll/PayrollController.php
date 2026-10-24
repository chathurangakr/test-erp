<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Actions\Payroll\PayPayroll;
use App\Actions\Payroll\ReversePayroll;
use App\Enums\Permission;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Api\V1\Concerns\RecordsOfflineEntries;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayPayrollRequest;
use App\Http\Resources\V1\Payroll\PayrollResource;
use App\Models\PayrollRun;
use App\Services\Accounting\CashDocumentOptions;
use App\Services\Payroll\PayrollCalculator;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Monthly salaries over the API — preview a month, then pay it.
 *
 * The preview is only a preview: PayPayroll works everything out again under lock at the moment
 * of paying, and the request carries no amounts. Paying requires an Idempotency-Key, so a
 * retried request replays the payroll instead of being refused as "already paid".
 */
class PayrollController extends Controller
{
    use PaginatesResults;
    use RecordsOfflineEntries;

    private const DETAIL = [
        'lines.employee:id,code,name',
        'cashBankAccount:id,display_name',
        'journalEntry.lines.account:id,code,name',
        'reversalJournalEntry:id,journal_number',
        'createdBy:id,name',
        'reversedBy:id,name',
    ];

    public function __construct(private readonly PayrollCalculator $calculator) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $runs = PayrollRun::query()
            ->with(['lines', 'cashBankAccount:id,display_name'])
            ->when($request->string('status')->toString(), fn ($q, string $s) => $q->where('status', $s))
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return PayrollResource::collection($runs);
    }

    /**
     * A month's salaries as they would be paid now, with the accounts and methods to pay from.
     */
    public function preview(Request $request, CashDocumentOptions $options): JsonResponse
    {
        $today = BusinessClock::today();

        // Any period (period_start … period_end) for everyone or `employee_id`; or, as before
        // 1.13.0, a whole `month` (last month when nothing is named).
        if ($request->filled('period_start')) {
            [$start, $end] = $this->calculator->periodBounds((string) $request->string('period_start'), (string) $request->string('period_end', (string) $request->string('period_start')));
            $month = null;
        } else {
            $month = (string) $request->string('month', $today->subMonthNoOverflow()->format('Y-m'));
            [$start, $end] = $this->calculator->monthBounds($month);
        }

        $only = $request->integer('employee_id') ? [$request->integer('employee_id')] : null;
        $payDate = $today->greaterThan($end) ? $today : $end;
        $lines = $this->calculator->lines($start, $end, $only, $payDate);
        $paidDays = $this->calculator->paidDays($start, $end, $only);
        // Kept for older apps: a payroll that paid every recorded day of the period, if one did.
        $paid = $paidDays->isNotEmpty() && $lines->isEmpty() ? $this->calculator->paidPayrollCovering($start) : null;

        $total = fn (string $key): string => DecimalMath::cost(DecimalMath::money($lines->reduce(
            fn (string $carry, array $line): string => DecimalMath::add($carry, $line[$key]),
            DecimalMath::zero(),
        )));

        return response()->json(['data' => [
            'month' => $month,
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'suggested_pay_date' => $payDate->toDateString(),
            'paid_payroll' => $paid === null ? null : ['id' => $paid->id, 'payroll_number' => $paid->payroll_number],
            'lines' => $lines->map(fn (array $line): array => [
                'employee' => ['id' => $line['employee']->id, 'code' => $line['employee']->code, 'name' => $line['employee']->name],
                'full_days' => $line['full_days'],
                'half_days' => $line['half_days'],
                'leave_days' => $line['leave_days'],
                'gross_pay' => DecimalMath::cost($line['gross_pay']),
                'advances_owed' => DecimalMath::cost($line['advances_owed']),
                // The most that may be taken back — and what is taken when the payment names no
                // amount for this employee in `deductions`.
                'advances_deducted' => DecimalMath::cost($line['advances_deducted']),
                'net_pay' => DecimalMath::cost($line['net_pay']),
                'days' => array_map(fn (array $day): array => ['pay' => DecimalMath::cost($day['pay'])] + $day, $line['days']),
            ])->values(),
            'paid_days' => $paidDays,
            'totals' => [
                'gross_pay' => $total('gross_pay'),
                'advances_deducted' => $total('advances_deducted'),
                'net_pay' => $total('net_pay'),
            ],
            'cash_bank_accounts' => $options->cashBankAccounts(),
            'payment_methods' => PayPayrollRequest::paymentMethodOptions(),
        ]]);
    }

    public function show(PayrollRun $payroll): PayrollResource
    {
        return new PayrollResource($payroll->load(self::DETAIL));
    }

    public function store(Request $request, PayPayroll $action): JsonResponse
    {
        return $this->recordOnce(
            $request,
            'payrolls',
            Permission::PayrollSalaryPay,
            PayPayrollRequest::class,
            fn (array $data, int $userId): PayrollRun => $action->handle($data, $userId),
            fn (PayrollRun $run): PayrollResource => new PayrollResource($run->load(self::DETAIL)),
        );
    }

    public function reverse(Request $request, PayrollRun $payroll, ReversePayroll $action): PayrollResource
    {
        return new PayrollResource($action->handle($payroll, $request->user()->id)->load(self::DETAIL));
    }
}
