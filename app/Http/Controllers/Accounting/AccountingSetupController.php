<?php

namespace App\Http\Controllers\Accounting;

use App\Actions\Accounting\UpdateAccountingSettings;
use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\UpdateAccountingSettingsRequest;
use App\Http\Requests\Accounting\UpdateProductAccountMappingRequest;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductAccountMapping;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounting setup — the cutover, the GRNI account, and the product-to-account mappings.
 *
 * This screen is where operational accounting is switched on. Until a start date is set,
 * stock moves exactly as it did before Finance existed and no journal is generated.
 *
 * Unmapped products are shown rather than silently assigned: a product nobody classified has
 * no accounting meaning, and guessing one would put wrong figures permanently into the books.
 */
class AccountingSetupController extends Controller
{
    public function index(): Response
    {
        $settings = AccountingSetting::current();

        $products = Product::query()
            ->with(['accountMapping.inventoryAccount:id,code,name', 'accountMapping.cogsAccount:id,code,name', 'accountMapping.revenueAccount:id,code,name'])
            ->orderBy('code')
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'is_active' => $product->is_active,
                'inventory_account' => $this->accountRef($product->accountMapping?->inventoryAccount),
                'cogs_account' => $this->accountRef($product->accountMapping?->cogsAccount),
                'revenue_account' => $this->accountRef($product->accountMapping?->revenueAccount),
                'is_mapped' => $product->accountMapping !== null,
            ]);

        return Inertia::render('finance/accounting-setup/Index', [
            'settings' => [
                'base_currency' => config('accounting.base_currency'),
                'operational_accounting_start_date' => $settings?->operational_accounting_start_date?->toDateString(),
                'grni_account' => $this->accountRef($settings?->grniAccount),
                'wages_account' => $this->accountRef($settings?->wagesAccount),
                'employee_advances_account' => $this->accountRef($settings?->employeeAdvancesAccount),
                'stock_loss_account' => $this->accountRef($settings?->stockLossAccount),
                'loans_account' => $this->accountRef($settings?->loansAccount),
                'loan_interest_account' => $this->accountRef($settings?->loanInterestAccount),
                'opening_balance_account' => $this->accountRef($settings?->openingBalanceAccount),
                // Once an operational journal exists the cutover is frozen: moving it would
                // leave the ledger and the books permanently disagreeing.
                'cutover_locked' => JournalEntry::query()->whereNotNull('source_type')->exists(),
                'operational_journal_count' => JournalEntry::query()->whereNotNull('source_type')->count(),
            ],
            'referenceAccounts' => [
                'trade_payables' => $this->accountRef(Account::where('code', '2110')->first()),
                'trade_receivables' => $this->accountRef(Account::where('code', '1210')->first()),
            ],
            'products' => $products,
            'unmappedCount' => $products->where('is_mapped', false)->count(),
            'accountOptions' => [
                'inventory' => $this->optionsFor(AccountType::Asset),
                'cogs' => $this->optionsFor(AccountType::CostOfSales),
                'revenue' => $this->optionsFor(AccountType::Revenue),
                'liability' => $this->optionsFor(AccountType::Liability),
                'expense' => $this->optionsFor(AccountType::Expense),
                'asset' => $this->optionsFor(AccountType::Asset),
                'equity' => $this->optionsFor(AccountType::Equity),
            ],
        ]);
    }

    public function updateSettings(UpdateAccountingSettingsRequest $request, UpdateAccountingSettings $action): RedirectResponse
    {
        $action->handle($request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Accounting settings updated.')]);

        return to_route('accounting-setup.index');
    }

    public function updateMapping(UpdateProductAccountMappingRequest $request, Product $product): RedirectResponse
    {
        ProductAccountMapping::updateOrCreate(
            ['product_id' => $product->id],
            $request->validated(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Accounts mapped for :product.', ['product' => $product->code]),
        ]);

        return to_route('accounting-setup.index');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function accountRef(?Account $account): ?array
    {
        return $account === null ? null : [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
        ];
    }

    /**
     * Active accounts of one class — the only ones a mapping may point at.
     *
     * Control accounts are included: a mapping is how the SYSTEM reaches them, which is
     * exactly what control accounts are for.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function optionsFor(AccountType $type)
    {
        return Account::query()
            ->where('is_active', true)
            ->where('account_type', $type)
            ->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
            ]);
    }
}
