<?php

namespace App\Http\Controllers\Advances;

use App\Enums\AdvanceStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\CustomerAdvance;
use App\Models\CustomerAdvanceApplication;
use App\Models\CustomerAdvanceRefund;
use App\Models\SupplierAdvance;
use App\Models\SupplierAdvanceApplication;
use App\Models\SupplierAdvanceRefund;
use App\Services\Accounting\AdvanceBalances;
use App\Services\Accounting\CashDocumentOptions;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screens customer and supplier advances share: a list, the form, and an advance with its
 * applications and refunds, and forms to apply or give back what is left. Each side's controller states its own models,
 * routes and actions; nothing here posts. See docs/ACCOUNTING_RULES.md §34.
 */
abstract class AdvanceScreens extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        protected readonly AdvanceBalances $balances,
        private readonly CashDocumentOptions $options,
    ) {}

    /** 'customer' or 'supplier'. */
    abstract protected function party(): string;

    /** @return class-string<CustomerAdvance|SupplierAdvance> */
    abstract protected function model(): string;

    /**
     * The party's invoices still owed, with what each still owes.
     *
     * @return Collection<int, array{id: int, number: string, date: string, outstanding: string}>
     */
    abstract protected function openInvoices(int $partyId): Collection;

    /** @return Collection<int, Model> active parties to choose from */
    abstract protected function parties(): Collection;

    protected function renderIndex(): Response
    {
        $party = $this->party();

        return Inertia::render("finance/{$party}-advances/Index", [
            'advances' => $this->model()::query()
                ->with("{$party}:id,code,name")
                ->orderByDesc('advance_date')
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE)
                ->through(fn (CustomerAdvance|SupplierAdvance $advance): array => $this->summarise($advance)),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    protected function renderCreate(): Response
    {
        return Inertia::render("finance/{$this->party()}-advances/Create", [
            'parties' => $this->parties()->map(fn (Model $party): array => ['id' => $party->id, 'code' => $party->code, 'name' => $party->name])->values(),
            'accounts' => $this->options->cashBankAccounts(),
            'paymentMethods' => PaymentMethod::options(),
            'today' => BusinessClock::todayString(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    protected function renderShow(CustomerAdvance|SupplierAdvance $advance): Response
    {
        $party = $this->party();
        $advance->load([$party, 'cashBankAccount', 'journalEntry:id,journal_number', 'reversalJournalEntry:id,journal_number', 'createdBy:id,name', 'reversedBy:id,name', 'applications.invoice', 'refunds.cashBankAccount']);
        $remaining = $this->balances->remaining($advance);

        return Inertia::render("finance/{$party}-advances/Show", [
            'advance' => $this->summarise($advance) + [
                'party_id' => $advance->{"{$party}_id"},
                'account' => $advance->cashBankAccount?->display_name,
                'payment_method_label' => $advance->payment_method->label(),
                'cheque_number' => $advance->cheque_number,
                'cheque_date' => $advance->cheque_date?->toDateString(),
                'cheque_bank' => $advance->cheque_bank,
                'reference' => $advance->reference,
                'note' => $advance->note,
                'journal_id' => $advance->journal_entry_id,
                'journal_number' => $advance->journalEntry?->journal_number,
                'created_by' => $advance->createdBy?->name,
                'posted_at' => BusinessClock::display($advance->posted_at),
                'reversed_by' => $advance->reversedBy?->name,
                'reversed_at' => BusinessClock::display($advance->reversed_at),
                'can_reverse' => $advance->status->canReverse()
                    && ! $advance->applications->contains(fn (Model $a): bool => $a->status === AdvanceStatus::Posted)
                    && ! $advance->refunds->contains(fn (Model $r): bool => $r->status === AdvanceStatus::Posted),
                'refunds' => $advance->refunds->sortByDesc('id')->map(fn (CustomerAdvanceRefund|SupplierAdvanceRefund $refund): array => [
                    'id' => $refund->id,
                    'date' => $refund->refund_date->toDateString(),
                    'account' => $refund->cashBankAccount?->display_name,
                    'payment_method_label' => $refund->payment_method->label(),
                    'reference' => $refund->reference,
                    'amount' => (string) $refund->amount,
                    'status' => $refund->status->value,
                    'status_label' => $refund->status->label(),
                ])->values(),
                'applications' => $advance->applications->sortByDesc('id')->map(fn (CustomerAdvanceApplication|SupplierAdvanceApplication $application): array => [
                    'id' => $application->id,
                    'date' => $application->application_date->toDateString(),
                    'invoice' => $application->invoice?->invoice_number ?? $application->invoice?->internal_invoice_number,
                    'amount' => (string) $application->amount,
                    'status' => $application->status->value,
                    'status_label' => $application->status->label(),
                ])->values(),
            ],
            'invoices' => $advance->status === AdvanceStatus::Posted && DecimalMath::isPositive($remaining)
                ? $this->openInvoices($advance->{"{$party}_id"})->values()
                : [],
            'canRefund' => $advance->status === AdvanceStatus::Posted && DecimalMath::isPositive($remaining),
            'accounts' => $this->options->cashBankAccounts(),
            'paymentMethods' => PaymentMethod::options(),
            'today' => BusinessClock::todayString(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(CustomerAdvance|SupplierAdvance $advance): array
    {
        $party = $advance->{$this->party()};

        return [
            'id' => $advance->id,
            'advance_number' => $advance->advance_number,
            'advance_date' => $advance->advance_date->toDateString(),
            'party' => $party === null ? null : $party->code.' — '.$party->name,
            'amount' => (string) $advance->amount,
            'remaining' => $this->balances->remaining($advance),
            'status' => $advance->status->value,
            'status_label' => $advance->status->label(),
        ];
    }
}
