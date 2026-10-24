<?php

namespace App\Http\Controllers\Loans;

use App\Actions\Loans\RecordLoan;
use App\Actions\Loans\RecordLoanPayment;
use App\Actions\Loans\ReverseLoan;
use App\Actions\Loans\ReverseLoanPayment;
use App\Actions\Loans\UpdateLoan;
use App\Enums\LoanDocumentStatus;
use App\Enums\LoanFunding;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Requests\Loans\StoreLoanPaymentRequest;
use App\Http\Requests\Loans\StoreLoanRequest;
use App\Http\Requests\Loans\UpdateLoanRequest;
use App\Http\Requests\Payroll\PayPayrollRequest;
use App\Models\Loan;
use App\Models\LoanPayment;
use App\Services\Accounting\CashDocumentOptions;
use App\Services\Loans\LoanBalances;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Loans the factory owes: what each still owes, when the next installment is due, and every
 * installment paid. Money is corrected by reversal; only the reminder details are edited.
 * See docs/LOANS.md.
 */
class LoanController extends Controller
{
    public function __construct(
        private readonly LoanBalances $balances,
        private readonly CashDocumentOptions $options,
    ) {}

    public function index(Request $request): Response
    {
        $showAll = $request->boolean('all');
        $today = BusinessClock::today();

        $loans = Loan::query()->orderBy('lender')->get()
            ->map(fn (Loan $loan): array => $this->summarise($loan, $today))
            ->filter(fn (array $loan): bool => $showAll || ($loan['status'] === LoanDocumentStatus::Posted->value && ! $loan['paid_off']))
            ->values();

        return Inertia::render('loans/Index', [
            'loans' => $loans,
            'showAll' => $showAll,
            'totals' => [
                'outstanding' => DecimalMath::money($loans->reduce(fn (string $sum, array $loan): string => DecimalMath::add($sum, $loan['outstanding']), DecimalMath::zero())),
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('loans/Create', [
            'fundings' => LoanFunding::options(),
            'accounts' => $this->options->cashBankAccounts(),
            'today' => BusinessClock::todayString(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(StoreLoanRequest $request, RecordLoan $action): RedirectResponse
    {
        $loan = $action->handle($request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Loan added.')]);

        return to_route('loans.show', $loan);
    }

    public function show(Loan $loan): Response
    {
        $loan->load(['cashBankAccount:id,display_name', 'journalEntry.lines.account:id,code,name', 'createdBy:id,name', 'reversedBy:id,name', 'payments.cashBankAccount:id,display_name']);
        $today = BusinessClock::today();

        return Inertia::render('loans/Show', [
            'loan' => $this->summarise($loan, $today) + [
                'purpose' => $loan->purpose,
                'notes' => $loan->notes,
                'funding_label' => $loan->funding->label(),
                'received_into' => $loan->cashBankAccount?->display_name,
                'principal_paid' => $this->balances->principalPaid($loan),
                'interest_paid' => $this->balances->interestPaid($loan),
                'created_by' => $loan->createdBy?->name,
                'posted_at' => BusinessClock::display($loan->posted_at),
                'reversed_by' => $loan->reversedBy?->name,
                'reversed_at' => BusinessClock::display($loan->reversed_at),
                'can_reverse' => $loan->status->canReverse() && ! $loan->payments->contains(fn (LoanPayment $payment): bool => $payment->status === LoanDocumentStatus::Posted),
                'journal' => PayrollController::journal($loan->journalEntry),
                'payments' => $loan->payments->sortByDesc('payment_date')->values()->map(fn (LoanPayment $payment): array => [
                    'id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'payment_date' => $payment->payment_date->toDateString(),
                    'amount' => (string) $payment->amount,
                    'interest_amount' => (string) $payment->interest_amount,
                    'principal_amount' => (string) $payment->principal_amount,
                    'paid_from' => $payment->cashBankAccount?->display_name,
                    'payment_method_label' => $payment->payment_method->label(),
                    'reference' => $payment->reference,
                    'is_reversed' => $payment->status === LoanDocumentStatus::Reversed,
                    'status_label' => $payment->status->label(),
                ]),
            ],
            'accounts' => $this->options->cashBankAccounts(),
            'paymentMethods' => PayPayrollRequest::paymentMethodOptions(),
            'today' => $today->toDateString(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function edit(Loan $loan): Response
    {
        return Inertia::render('loans/Edit', [
            'loan' => $loan->only(['id', 'loan_number', ...Loan::EDITABLE]),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function update(UpdateLoanRequest $request, Loan $loan, UpdateLoan $action): RedirectResponse
    {
        $action->handle($loan, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Loan updated.')]);

        return to_route('loans.show', $loan);
    }

    public function reverse(Loan $loan, ReverseLoan $action): RedirectResponse
    {
        $action->handle($loan, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Loan reversed.')]);

        return to_route('loans.show', $loan);
    }

    public function storePayment(StoreLoanPaymentRequest $request, Loan $loan, RecordLoanPayment $action): RedirectResponse
    {
        $action->handle($loan, $request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment recorded.')]);

        return to_route('loans.show', $loan);
    }

    public function reversePayment(LoanPayment $loanPayment, ReverseLoanPayment $action): RedirectResponse
    {
        $action->handle($loanPayment, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment reversed.')]);

        return to_route('loans.show', $loanPayment->loan_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Loan $loan, $today): array
    {
        $outstanding = $this->balances->outstanding($loan);
        $next = $loan->status === LoanDocumentStatus::Posted && DecimalMath::isPositive($outstanding) ? $this->balances->nextDueDate($loan, $today) : null;

        return [
            'id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'lender' => $loan->lender,
            'start_date' => $loan->start_date->toDateString(),
            'amount' => (string) $loan->amount,
            'outstanding' => $outstanding,
            'paid_off' => ! DecimalMath::isPositive($outstanding),
            'has_interest' => $loan->has_interest,
            'installment_amount' => $loan->installment_amount === null ? null : (string) $loan->installment_amount,
            'due_day' => $loan->due_day,
            'next_due_date' => $next?->toDateString(),
            'next_due_paid' => $next !== null && $this->balances->isInstallmentPaid($loan, $next),
            'days_to_due' => $next === null ? null : (int) $today->diffInDays($next),
            'status' => $loan->status->value,
            'status_label' => $loan->status->label(),
        ];
    }
}
