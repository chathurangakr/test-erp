<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Master data first: products depend on units of measure existing.
        $this->call([
            UnitOfMeasureSeeder::class,
            ProductSeeder::class,
            WarehouseSeeder::class,
            ProductionRecipeSeeder::class,
            // Accounting configuration. No fiscal period and no journal is seeded — the
            // books start genuinely empty.
            ChartOfAccountsSeeder::class,
            // Connects the product master to the chart. Sets no cutover date — switching
            // operational accounting on is an accountant's decision.
            ProductAccountMappingSeeder::class,
            // Records which chart accounts are real tills and bank accounts. Configuration
            // only: no balances, no opening entries, no transactions.
            CashBankAccountSeeder::class,
        ]);

        // Roles and permissions. Seeds no user and no password; on first introduction it
        // makes the users who already exist administrators so nobody is locked out.
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
