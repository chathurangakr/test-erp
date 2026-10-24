<?php

namespace App\Http\Controllers\Payroll;

use App\Actions\Payroll\PayPayroll;
use App\Actions\Payroll\ReversePayroll;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayPayrollRequest;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Services\Accounting\CashDocumentOptions;
use App\Services\Payroll\PayrollCalculator;
use App\Support\BusinessClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Salaries — preview any period (a day, a few days, a month) for everyone or one person, then
 * pay it in one step.
 *
 * The preview is only a preview: PayPayroll works everything out again at the moment of
 * paying. A payment is corrected by reversing it, never by editing.
 */
class PayrollController extends Controller
{
    private const PER_PAGE = 24;

    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly CashDocumentOptions $options,
    ) {}

    public function index(): Response
    {
        $runs = PayrollRun::query()
            ->with(['lines', 'cashBankAccount:id,display_name'])
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->through(fn (PayrollRun $run): array => $this->summarise($run));

        return Inertia::render('payroll/payrolls/Index', [
            'payrolls' => $runs,
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(Request $request): Response
    {
        $today = BusinessClock::today();
        [$start, $end] = $this->calculator->periodBounds(
            (string) $request->string('from', $today->startOfMonth()->toDateString()),
            (string) $request->string('to', $today->toDateString()),
        );
        $employeeId = $request->integer('employee_id') ?: null;
        $only = $employeeId === null ? null : [$employeeId];
        $payDate = $today->greaterThan($end) ? $today : $end;

        $lines = $this->calculator->lines($start, $end, $only, $payDate)->map(fn (array $line): array => [
            'employee_id' => $line['employee']->id,
            'code' => $line['employee']->code,
            'name' => $line['employee']->name,
            'full_days' => $line['full_days'],
            'half_days' => $line['half_days'],
            'leave_days' => $line['leave_days'],
            'gross_pay' => $line['gross_pay'],
            'advances_owed' => $line['advances_owed'],
            // The most that may be taken back; the payer chooses up to this.
            'advances_deducted' => $line['advances_deducted'],
            'net_pay' => $line['net_pay'],
            'days' => $line['days'],
        ])->values();

        return Inertia::render('payroll/payrolls/Create', [
            'periodStart' => $start->toDateString(),
            'periodEnd' => $end->toDateString(),
            'today' => $today->toDateString(),
            'employeeId' => $employeeId,
            'employees' => Employee::query()->orderBy('name')->get(['id', 'code', 'name', 'is_active']),
            'payDate' => $payDate->toDateString(),
            'lines' => $lines,
            // Days in the period already paid, so nobody tries to pay them again.
            'paidDays' => $this->calculator->paidDays($start, $end, $only),
            'accounts' => $this->options->cashBankAccounts(),
            'paymentMethods' => PayPayrollRequest::paymentMethodOptions(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(PayPayrollRequest $request, PayPayroll $action): RedirectResponse
    {
        $run = $action->handle($request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Salaries paid.')]);

        return to_route('payrolls.show', $run);
    }

    public function show(PayrollRun $payroll): Response
    {
        $payroll->load([
            'lines.employee:id,code,name',
            'cashBankAccount:id,display_name',
            'journalEntry.lines.account:id,code,name',
            'reversalJournalEntry:id,journal_number',
            'createdBy:id,name',
            'reversedBy:id,name',
        ]);

        return Inertia::render('payroll/payrolls/Show', [
            'payroll' => $this->summarise($payroll) + [
                'pay_date' => $payroll->pay_date->toDateString(),
                'payment_method_label' => $payroll->payment_method->label(),
                'reference' => $payroll->reference,
                'created_by' => $payroll->createdBy?->name,
                'posted_at' => BusinessClock::display($payroll->posted_at),
                'reversed_by' => $payroll->reversedBy?->name,
                'reversed_at' => BusinessClock::display($payroll->reversed_at),
                'reversal_journal_number' => $payroll->reversalJournalEntry?->journal_number,
                'can_reverse' => $payroll->status->canReverse(),
                'lines' => $payroll->lines->sortBy(fn (PayrollLine $line): string => (string) $line->employee?->name)
                    ->map(fn (PayrollLine $line): array => [
                        'employee_id' => $line->employee_id,
                        'code' => $line->employee?->code,
                        'name' => $line->employee?->name,
                        'full_days' => $line->full_days,
                        'half_days' => $line->half_days,
                        'leave_days' => $line->leave_days,
                        'gross_pay' => (string) $line->gross_pay,
                        'advances_deducted' => (string) $line->advances_deducted,
                        'net_pay' => (string) $line->net_pay,
                    ])->values(),
                'journal' => self::journal($payroll->journalEntry),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function reverse(PayrollRun $payroll, ReversePayroll $action): RedirectResponse
    {
        $action->handle($payroll, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payroll reversed. Its attendance days can be corrected and paid again.')]);

        return to_route('payrolls.show', $payroll);
    }

    /**
     * A payroll document's journal, for display.
     *
     * @return array<string, mixed>|null
     */
    public static function journal(?JournalEntry $journal): ?array
    {
        return $journal === null ? null : [
            'id' => $journal->id,
            'journal_number' => $journal->journal_number,
            'lines' => $journal->lines->map(fn ($line): array => [
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'debit' => $line->debit,
                'credit' => $line->credit,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(PayrollRun $run): array
    {
        return [
            'id' => $run->id,
            'payroll_number' => $run->payroll_number,
            'month' => $run->period_start->format('Y-m'),
            'period_start' => $run->period_start->toDateString(),
            'period_end' => $run->period_end->toDateString(),
            'pay_date' => $run->pay_date->toDateString(),
            'employees' => $run->lines->count(),
            'gross_pay' => $run->total('gross_pay'),
            'advances_deducted' => $run->total('advances_deducted'),
            'net_pay' => $run->total('net_pay'),
            'payment_account' => $run->cashBankAccount?->display_name,
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
        ];
    }
}
