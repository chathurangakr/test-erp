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
    ];

    private const GRNI_CODE = '2120';

    private const TRADE_PAYABLES_CODE = '2110';

    private const TRADE_RECEIVABLES_CODE = '1210';

    private const PRODUCTION_LABOUR_CODE = '6310';

    private const WAGES_CODE = '6300';

    private const EMPLOYEE_ADVANCES_CODE = '1220';

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
    ): void {
        $attributes = array_filter([
            'grni_account_id' => $grniAccountId,
            'trade_payables_account_id' => $tradePayablesAccountId,
            'trade_receivables_account_id' => $tradeReceivablesAccountId,
            'production_labour_account_id' => $productionLabourAccountId,
            'wages_account_id' => $wagesAccountId,
            'employee_advances_account_id' => $employeeAdvancesAccountId,
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

        $settings->update($attributes);
    }
}
