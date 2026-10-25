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
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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

    /** @return class-string<Model> the party model: Customer or Supplier */
    abstract protected function partyClass(): string;

    protected function renderIndex(Request $request): Response
    {
        $party = $this->party();
        $table = (new ($this->model()))->getTable();
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status');
        $partyId = $request->integer('party_id') ?: null;
        $list = ListQuery::from($request)->period('advance_date')->sortable([
            'number' => 'advance_number',
            'date' => 'advance_date',
            'party' => fn (Builder $q, string $d) => $q->orderBy($this->partyClass()::select('code')->whereColumn("{$party}s.id", "{$table}.{$party}_id"), $d),
            'amount' => 'amount',
            'status' => 'status',
        ], 'date');

        return Inertia::render("finance/{$party}-advances/Index", [
            'advances' => $list->paginate($this->model()::query()
                ->with("{$party}:id,code,name")
                ->when($partyId, fn (Builder $q, int $id) => $q->where("{$party}_id", $id))
                ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
                ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                    ->where('advance_number', 'like', "%{$search}%")
                    ->orWhereHas($party, fn (Builder $p) => $p->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))))
                ->through(fn (CustomerAdvance|SupplierAdvance $advance): array => $this->summarise($advance)),
            'filters' => ['search' => $search, 'status' => $status, 'party_id' => $partyId] + $list->filters(),
            'statuses' => array_map(fn (AdvanceStatus $s): array => ['value' => $s->value, 'label' => $s->label()], AdvanceStatus::cases()),
            'parties' => $this->parties()->map(fn (Model $p): array => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name])->values(),
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
