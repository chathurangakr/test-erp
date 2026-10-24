<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\Product;
use App\Models\ProductAccountMapping;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Which accounts each official product's stock movements post to.
 *
 * This seeder and `ChartOfAccountsSeeder` are the only places that know an account code.
 * Everything else asks the mapping, so renumbering the chart is a data change.
 *
 * **Only the eight seeded products are mapped.** A product created by hand — the QA test
 * product, say — is deliberately left unmapped: inventing accounts for a product nobody
 * classified would put guessed figures permanently into the books. Such a product simply
 * cannot post once accounting is switched on, and shows as unmapped in Accounting Setup.
 *
 * It also points the settings at the GRNI, Trade Payables and Trade Receivables control
 * accounts, and **never
 * invents a cutover date** — switching accounting on is an accountant's decision, not a
 * seeder's.
 *
 * Idempotent: re-running updates the mapping without disturbing posted history.
 */
class ProductAccountMappingSeeder extends Seeder
{
    /**
     * product code => [inventory, cogs, revenue]
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const MAPPINGS = [
        'COCONUT' => ['1310', '5110', '4110'],
        'DEHUSKED-COCONUT' => ['1320', '5120', '4120'],
        // Every size shares its kind's accounts: the size detail lives in the inventory ledger.
        'COCONUT-LARGE' => ['1310', '5110', '4110'],
        'COCONUT-SMALL' => ['1310', '5110', '4110'],
        'COCONUT-IMMATURE' => ['1310', '5110', '4110'],
        'DEHUSKED-LARGE' => ['1320', '5120', '4120'],
        'DEHUSKED-SMALL' => ['1320', '5120', '4120'],
        'DEHUSKED-IMMATURE' => ['1320', '5120', '4120'],
        'RAW-HUSK' => ['1330', '5160', '4160'],
        // The three chip grades share one inventory, cost and revenue account: the factory
        // reports them together, and the grade detail lives in the inventory ledger.
        'HUSK-7C' => ['1340', '5130', '4130'],
        'HUSK-9C' => ['1340', '5130', '4130'],
        'HUSK-10C' => ['1340', '5130', '4130'],
        // The litre stock items are the same grades measured another way, so they share
        // their grade's accounts. Cocopeat is its own product with its own accounts.
        'HUSK-7C-L' => ['1340', '5130', '4130'],
        'HUSK-9C-L' => ['1340', '5130', '4130'],
        'HUSK-10C-L' => ['1340', '5130', '4130'],
        'COCOPEAT' => ['1370', '5170', '4170'],
        'COCOPEAT-L' => ['1370', '5170', '4170'],
        'COPRA' => ['1350', '5140', '4140'],
        'RAW-SHELL' => ['1360', '5150', '4150'],
        'PP-BAG' => ['1380', '5180', '4180'],
    ];

    private const GRNI_CODE = '2120';

    private const TRADE_PAYABLES_CODE = '2110';

    private const TRADE_RECEIVABLES_CODE = '1210';

    private const PRODUCTION_LABOUR_CODE = '6310';

    private const WAGES_CODE = '6300';

    private const EMPLOYEE_ADVANCES_CODE = '1220';

    private const STOCK_LOSS_CODE = '6900';

    /** @var array<string, string> setting column => account code */
    private const MORE_SETTING_ACCOUNTS = [
        'loans_account_id' => '2400',
        'loan_interest_account_id' => '6950',
        'opening_balance_account_id' => '3300',
        'fixed_assets_account_id' => '1500',
        'asset_gain_account_id' => '4900',
        'asset_loss_account_id' => '6960',
        'maintenance_account_id' => '6600',
    ];

    /**
     * When true, only what does not exist yet is created; nothing existing is changed.
     */
    private bool $onlyMissing = false;

    /**
     * Add what a newer version introduced to an installed database, leaving every existing row
     * exactly as people left it. Run by `masters:add-new` on each update.
     */
    public function addMissing(): void
    {
        $this->onlyMissing = true;
        $this->run();
    }

    public function run(): void
    {
        $accounts = Account::query()->pluck('id', 'code');

        foreach (self::MAPPINGS as $productCode => [$inventory, $cogs, $revenue]) {
            $product = Product::query()->where('code', $productCode)->first();

            if ($product === null) {
                // The product master is seeded separately; skip rather than fail, so this
                // seeder can run against a partially built database.
                continue;
            }

            if ($this->onlyMissing && ProductAccountMapping::query()->where('product_id', $product->id)->exists()) {
                continue;
            }

            foreach ([$inventory, $cogs, $revenue] as $code) {
                if (! $accounts->has($code)) {
                    throw new RuntimeException(
                        "Account [{$code}] is missing. Seed the chart of accounts before the product mappings."
                    );
                }
            }

            ProductAccountMapping::updateOrCreate(
                ['product_id' => $product->id],
                [
                    'inventory_account_id' => $accounts[$inventory],
                    'cogs_account_id' => $accounts[$cogs],
                    // Configured now; no revenue journal is created until sales invoicing.
                    'revenue_account_id' => $accounts[$revenue],
                ],
            );
        }

        $this->configureControlAccounts(
            $accounts->get(self::GRNI_CODE),
            $accounts->get(self::TRADE_PAYABLES_CODE),
            $accounts->get(self::TRADE_RECEIVABLES_CODE),
            $accounts->get(self::PRODUCTION_LABOUR_CODE),
            $accounts->get(self::WAGES_CODE),
            $accounts->get(self::EMPLOYEE_ADVANCES_CODE),
            $accounts->get(self::STOCK_LOSS_CODE),
        );
    }

    /**
     * Point the settings row at the control accounts, leaving the cutover date alone.
     */
    private function configureControlAccounts(
        ?int $grniAccountId,
        ?int $tradePayablesAccountId,
        ?int $tradeReceivablesAccountId,
        ?int $productionLabourAccountId,
        ?int $wagesAccountId,
        ?int $employeeAdvancesAccountId,
        ?int $stockLossAccountId,
    ): void {
        $attributes = array_filter([
            'grni_account_id' => $grniAccountId,
            'trade_payables_account_id' => $tradePayablesAccountId,
            'trade_receivables_account_id' => $tradeReceivablesAccountId,
            'production_labour_account_id' => $productionLabourAccountId,
            'wages_account_id' => $wagesAccountId,
            'employee_advances_account_id' => $employeeAdvancesAccountId,
            'stock_loss_account_id' => $stockLossAccountId,
            ...array_map(fn (string $code): ?int => Account::query()->where('code', $code)->value('id'), self::MORE_SETTING_ACCOUNTS),
        ], fn (?int $id): bool => $id !== null);

        if ($attributes === []) {
            return;
        }

        $settings = AccountingSetting::current();

        if ($settings === null) {
            AccountingSetting::create(array_merge([
                // NULL on purpose: accounting is not switched on until someone decides to.
                'operational_accounting_start_date' => null,
            ], $attributes));

            return;
        }

        // On an update only settings nobody has chosen yet are filled in.
        $settings->update($this->onlyMissing
            ? array_filter($attributes, fn (int $id, string $column): bool => $settings->{$column} === null, ARRAY_FILTER_USE_BOTH)
            : $attributes);
    }
}
