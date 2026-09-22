<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\StoreAccountRequest;
use App\Http\Requests\Accounting\UpdateAccountRequest;
use App\Models\Account;
use App\Services\Accounting\AccountingBalanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The chart of accounts.
 *
 * There is deliberately **no destroy route**. An account that has been posted to must keep
 * existing for that history to mean anything; an account that is no longer used is
 * deactivated instead. This matches every other master in the system.
 */
class AccountController extends Controller
{
    private const PER_PAGE = 50;

    public function __construct(private readonly AccountingBalanceService $balances) {}

    public function index(Request $request): Response
    {
        $type = (string) $request->string('type');
        $status = (string) $request->string('status');

        $accounts = Account::query()
            ->with('parent:id,code,name')
            ->search((string) $request->string('search'))
            ->when($type, fn (Builder $q, string $t) => $q->where('account_type', $t))
            ->when($status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('code')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (Account $account): array => $this->summarise($account));

        return Inertia::render('finance/accounts/Index', [
            'accounts' => $accounts,
            'filters' => [
                'search' => (string) $request->string('search'),
                'type' => $type ?: '',
                'status' => $status ?: 'all',
            ],
            'types' => AccountType::options(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('finance/accounts/Create', $this->formOptions());
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $account = Account::create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account created.')]);

        return to_route('accounts.show', $account);
    }

    public function show(Account $account): Response
    {
        $account->load(['parent:id,code,name', 'children:id,parent_id,code,name,is_active']);

        return Inertia::render('finance/accounts/Show', [
            'account' => array_merge($this->summarise($account), [
                'description' => $account->description,
                'children' => $account->children->map(fn (Account $child): array => [
                    'id' => $child->id,
                    'code' => $child->code,
                    'name' => $child->name,
                    'is_active' => $child->is_active,
                ])->values(),
                'debit_total' => $this->balances->debitTotal($account->id),
                'credit_total' => $this->balances->creditTotal($account->id),
                'balance' => $this->balances->balance($account),
                'has_history' => $account->lines()->exists(),
            ]),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function edit(Account $account): Response
    {
        return Inertia::render('finance/accounts/Edit', array_merge($this->formOptions($account), [
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'account_type' => $account->account_type->value,
                'normal_balance' => $account->normal_balance->value,
                'parent_id' => $account->parent_id,
                'is_control_account' => $account->is_control_account,
                'allow_manual_posting' => $account->allow_manual_posting,
                'is_active' => $account->is_active,
                'description' => $account->description,
            ],
        ]));
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $account->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account updated.')]);

        return to_route('accounts.show', $account);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(?Account $account = null): array
    {
        return [
            'types' => AccountType::options(),
            'normalBalances' => NormalBalance::options(),
            'parents' => Account::query()
                ->when($account, fn (Builder $q, Account $a) => $q->whereKeyNot($a->id))
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Account $a): array => [
                    'id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name,
                ]),
            // The conventional side for each type, so the form fills itself in sensibly.
            'defaultNormalBalances' => collect(AccountType::cases())
                ->mapWithKeys(fn (AccountType $type): array => [
                    $type->value => $type->defaultNormalBalance()->value,
                ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(Account $account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'account_type' => $account->account_type->value,
            'account_type_label' => $account->account_type->label(),
            'normal_balance' => $account->normal_balance->value,
            'normal_balance_label' => $account->normal_balance->label(),
            'parent_code' => $account->parent?->code,
            'parent_name' => $account->parent?->name,
            'is_control_account' => $account->is_control_account,
            'allow_manual_posting' => $account->allow_manual_posting,
            'accepts_manual_posting' => $account->acceptsManualPosting(),
            'is_active' => $account->is_active,
        ];
    }
}
