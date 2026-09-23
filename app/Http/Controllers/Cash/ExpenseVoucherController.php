<?php

namespace App\Http\Controllers\Cash;

use App\Actions\Cash\GenerateCashReference;
use App\Enums\AccountType;
use App\Enums\ExpenseVoucherStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\StoreExpenseVoucherRequest;
use App\Http\Requests\Cash\UpdateExpenseVoucherRequest;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\ExpenseVoucher;
use App\Models\Supplier;
use App\Support\DecimalMath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Expense vouchers — an expense paid immediately from cash or the bank.
 *
 * Only a DRAFT may be edited. A posted voucher is a controlled document: correct it by
 * reversal, never by editing, and there is no destroy route at all.
 */
class ExpenseVoucherController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly GenerateCashReference $references) {}

    public function index(Request $request): Response
    {
        $status = (string) $request->string('status');
        $accountId = $request->integer('cash_bank_account_id') ?: null;

        $vouchers = ExpenseVoucher::query()
            ->with(['cashBankAccount:id,display_name', 'supplier:id,code,name', 'lines'])
            ->search((string) $request->string('search'))
            ->when($status, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when($accountId, fn (Builder $q, int $id) => $q->where('cash_bank_account_id', $id))
            ->orderByDesc('voucher_date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (ExpenseVoucher $voucher): array => $this->summarise($voucher));

        return Inertia::render('finance/expense-vouchers/Index', [
            'vouchers' => $vouchers,
            'filters' => [
                'search' => (string) $request->string('search'),
                'status' => $status ?: '',
                'cash_bank_account_id' => $accountId,
            ],
            'statuses' => ExpenseVoucherStatus::options(),
            'accounts' => $this->cashBankOptions(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('finance/expense-vouchers/Create', [
            'accounts' => $this->cashBankOptions(),
            'expenseAccounts' => $this->expenseAccounts(),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(StoreExpenseVoucherRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $voucher = DB::transaction(function () use ($data): ExpenseVoucher {
            $voucher = ExpenseVoucher::create([
                'voucher_number' => $this->references->forExpenseVoucher(Carbon::parse($data['voucher_date'])),
                'voucher_date' => $data['voucher_date'],
                'cash_bank_account_id' => $data['cash_bank_account_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'payee_name' => $data['payee_name'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
                'status' => ExpenseVoucherStatus::Draft,
                'created_by' => auth()->id(),
            ]);

            $this->writeLines($voucher, $data['lines']);

            return $voucher;
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Expense voucher saved as draft.')]);

        return to_route('expense-vouchers.show', $voucher);
    }

    public function show(ExpenseVoucher $expenseVoucher): Response
    {
        $expenseVoucher->load([
            'lines.expenseAccount:id,code,name',
            'cashBankAccount.account:id,code,name',
            'supplier:id,code,name',
            'journalEntry.lines.account:id,code,name',
            'reversalJournalEntry:id,journal_number',
            'createdBy:id,name',
            'postedBy:id,name',
            'reversedBy:id,name',
        ]);

        return Inertia::render('finance/expense-vouchers/Show', [
            'voucher' => $this->detail($expenseVoucher),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function edit(ExpenseVoucher $expenseVoucher): Response
    {
        $this->assertEditable($expenseVoucher);

        $expenseVoucher->load('lines');

        return Inertia::render('finance/expense-vouchers/Edit', [
            'voucher' => [
                'id' => $expenseVoucher->id,
                'voucher_number' => $expenseVoucher->voucher_number,
                'voucher_date' => $expenseVoucher->voucher_date?->toDateString(),
                'cash_bank_account_id' => $expenseVoucher->cash_bank_account_id,
                'supplier_id' => $expenseVoucher->supplier_id,
                'payee_name' => $expenseVoucher->payee_name,
                'reference' => $expenseVoucher->reference,
                'description' => $expenseVoucher->description,
                'lines' => $expenseVoucher->lines->map(fn ($line): array => [
                    'expense_account_id' => $line->expense_account_id,
                    'description' => $line->description,
                    'amount' => $line->amount,
                ])->values(),
            ],
            'accounts' => $this->cashBankOptions(),
            'expenseAccounts' => $this->expenseAccounts(),
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function update(UpdateExpenseVoucherRequest $request, ExpenseVoucher $expenseVoucher): RedirectResponse
    {
        $this->assertEditable($expenseVoucher);

        $data = $request->validated();

        DB::transaction(function () use ($expenseVoucher, $data): void {
            $expenseVoucher->update([
                'voucher_date' => $data['voucher_date'],
                'cash_bank_account_id' => $data['cash_bank_account_id'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'payee_name' => $data['payee_name'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? null,
            ]);

            // A draft's lines are replaced wholesale; nothing has been posted from them.
            $expenseVoucher->lines()->delete();
            $this->writeLines($expenseVoucher, $data['lines']);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Expense voucher updated.')]);

        return to_route('expense-vouchers.show', $expenseVoucher);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function writeLines(ExpenseVoucher $voucher, array $lines): void
    {
        foreach ($lines as $line) {
            $voucher->lines()->create([
                'expense_account_id' => $line['expense_account_id'],
                'description' => $line['description'] ?? null,
                'amount' => DecimalMath::cost(DecimalMath::of((string) $line['amount'])),
            ]);
        }
    }

    private function assertEditable(ExpenseVoucher $voucher): void
    {
        if (! $voucher->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft expense voucher can be edited. :reference is :status — reverse it instead.', [
                    'reference' => $voucher->voucher_number,
                    'status' => $voucher->status->label(),
                ]),
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cashBankOptions(): array
    {
        return CashBankAccount::query()
            ->with('account:id,code,name,is_active')
            ->where('is_active', true)
            ->get()
            ->filter(fn (CashBankAccount $configuration): bool => $configuration->account?->is_active === true)
            ->sortBy(fn (CashBankAccount $configuration): string => (string) $configuration->account?->code)
            ->map(fn (CashBankAccount $configuration): array => [
                'id' => $configuration->id,
                'code' => $configuration->account?->code,
                'name' => $configuration->display_name,
                'kind' => $configuration->account_kind->label(),
            ])
            ->values()
            ->all();
    }

    /**
     * Ordinary operating expense accounts only.
     *
     * Cost of sales is absent on purpose: it is derived from stock movements, and charging it
     * by hand would put it out of step with the inventory ledger.
     *
     * @return list<array<string, mixed>>
     */
    private function expenseAccounts(): array
    {
        return Account::query()
            ->where('is_active', true)
            ->where('account_type', AccountType::Expense)
            ->where('is_control_account', false)
            ->where('allow_manual_posting', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(ExpenseVoucher $voucher): array
    {
        return [
            'id' => $voucher->id,
            'voucher_number' => $voucher->voucher_number,
            'voucher_date' => $voucher->voucher_date?->toDateString(),
            'payee' => $voucher->payee(),
            'supplier_code' => $voucher->supplier?->code,
            'payment_account' => $voucher->cashBankAccount?->display_name,
            'reference' => $voucher->reference,
            'total' => $voucher->total(),
            'status' => $voucher->status->value,
            'status_label' => $voucher->status->label(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(ExpenseVoucher $voucher): array
    {
        return array_merge($this->summarise($voucher), [
            'description' => $voucher->description,
            'payment_account_code' => $voucher->cashBankAccount?->account?->code,
            'created_by' => $voucher->createdBy?->name,
            'posted_by' => $voucher->postedBy?->name,
            'posted_at' => $voucher->posted_at?->toDateTimeString(),
            'reversed_by' => $voucher->reversedBy?->name,
            'reversed_at' => $voucher->reversed_at?->toDateTimeString(),
            'can_edit' => $voucher->status->isEditable(),
            'can_post' => $voucher->status->canPost(),
            'can_cancel' => $voucher->status->canCancel(),
            'can_reverse' => $voucher->status->canReverse() && $voucher->reversal_journal_entry_id === null,
            'lines' => $voucher->lines->map(fn ($line): array => [
                'id' => $line->id,
                'account_code' => $line->expenseAccount?->code,
                'account_name' => $line->expenseAccount?->name,
                'description' => $line->description,
                'amount' => $line->amount,
            ])->values(),
            'journal' => $voucher->journalEntry === null ? null : [
                'id' => $voucher->journalEntry->id,
                'journal_number' => $voucher->journalEntry->journal_number,
                'lines' => $voucher->journalEntry->lines->map(fn ($line): array => [
                    'account_code' => $line->account?->code,
                    'account_name' => $line->account?->name,
                    'debit' => $line->debit,
                    'credit' => $line->credit,
                ])->values(),
            ],
            'reversal_journal_number' => $voucher->reversalJournalEntry?->journal_number,
        ]);
    }
}
