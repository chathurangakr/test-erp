<?php

namespace App\Http\Controllers\Payroll;

use App\Actions\Payroll\RecordSalaryAdvance;
use App\Actions\Payroll\ReverseSalaryAdvance;
use App\Enums\PayrollDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\StoreSalaryAdvanceRequest;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use App\Services\Accounting\CashDocumentOptions;
use App\Support\BusinessClock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Salary advances — money given to employees before payday, paid and journalled in one step.
 * There is no edit: a mistake is reversed.
 */
class SalaryAdvanceController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly CashDocumentOptions $options) {}

    public function index(Request $request): Response
    {
        $employeeId = $request->integer('employee_id') ?: null;

        $advances = SalaryAdvance::query()
            ->with(['employee:id,code,name', 'cashBankAccount:id,display_name'])
            ->when($employeeId, fn ($query, int $id) => $query->where('employee_id', $id))
            ->orderByDesc('advance_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (SalaryAdvance $advance): array => $this->summarise($advance));

        return Inertia::render('payroll/salary-advances/Index', [
            'advances' => $advances,
            'filters' => ['employee_id' => $employeeId],
            'employees' => Employee::query()->orderBy('name')->get(['id', 'code', 'name']),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('payroll/salary-advances/Create', [
            'employees' => Employee::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'employeeId' => $request->integer('employee_id') ?: null,
            'accounts' => $this->options->cashBankAccounts(),
            'paymentMethods' => StoreSalaryAdvanceRequest::paymentMethodOptions(),
            'today' => BusinessClock::todayString(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(StoreSalaryAdvanceRequest $request, RecordSalaryAdvance $action): RedirectResponse
    {
        $advance = $action->handle($request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Salary advance paid.')]);

        return to_route('salary-advances.show', $advance);
    }

    public function show(SalaryAdvance $salaryAdvance): Response
    {
        $salaryAdvance->load([
            'employee:id,code,name',
            'cashBankAccount:id,display_name',
            'journalEntry.lines.account:id,code,name',
            'reversalJournalEntry:id,journal_number',
            'createdBy:id,name',
            'reversedBy:id,name',
        ]);

        return Inertia::render('payroll/salary-advances/Show', [
            'advance' => $this->summarise($salaryAdvance) + [
                'employee_id' => $salaryAdvance->employee_id,
                'payment_method_label' => $salaryAdvance->payment_method->label(),
                'reference' => $salaryAdvance->reference,
                'note' => $salaryAdvance->note,
                'created_by' => $salaryAdvance->createdBy?->name,
                'posted_at' => BusinessClock::display($salaryAdvance->posted_at),
                'reversed_by' => $salaryAdvance->reversedBy?->name,
                'reversed_at' => BusinessClock::display($salaryAdvance->reversed_at),
                'reversal_journal_number' => $salaryAdvance->reversalJournalEntry?->journal_number,
                'can_reverse' => $salaryAdvance->status->canReverse(),
                'journal' => PayrollController::journal($salaryAdvance->journalEntry),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function reverse(SalaryAdvance $salaryAdvance, ReverseSalaryAdvance $action): RedirectResponse
    {
        $action->handle($salaryAdvance, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Salary advance reversed.')]);

        return to_route('salary-advances.show', $salaryAdvance);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(SalaryAdvance $advance): array
    {
        return [
            'id' => $advance->id,
            'advance_number' => $advance->advance_number,
            'advance_date' => $advance->advance_date->toDateString(),
            'employee_code' => $advance->employee?->code,
            'employee_name' => $advance->employee?->name,
            'amount' => (string) $advance->amount,
            'payment_account' => $advance->cashBankAccount?->display_name,
            'status' => $advance->status->value,
            'status_label' => $advance->status->label(),
            'is_reversed' => $advance->status === PayrollDocumentStatus::Reversed,
        ];
    }
}
