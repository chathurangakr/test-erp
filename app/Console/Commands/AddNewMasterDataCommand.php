<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Product;
use App\Models\ProductionRecipe;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Database\Seeders\ProductionRecipeSeeder;
use Database\Seeders\ProductSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Brings master data a newer version introduced into an installed database: new ledger
 * accounts, products, their account mappings, production recipes and unset accounting
 * settings. It only ADDS what is missing — an account, product, mapping or recipe that already
 * exists is left exactly as people left it, renamed or not. Run by deploy/provision.sh on every
 * update; safe to run any number of times.
 */
#[Signature('masters:add-new')]
#[Description('Add master data introduced by a newer version, never changing what exists')]
class AddNewMasterDataCommand extends Command
{
    public function handle(): int
    {
        // Nothing to add to: a fresh install is seeded whole by db:seed.
        if (! Product::query()->exists()) {
            $this->info('No master data yet; nothing to add.');

            return self::SUCCESS;
        }

        $before = [Account::count(), Product::count(), ProductionRecipe::count()];

        DB::transaction(function (): void {
            app(ChartOfAccountsSeeder::class)->addMissing();
            app(ProductSeeder::class)->addMissing();

            // Mappings and settings need the chart; an install still without one skips them.
            if (Account::query()->exists()) {
                app(ProductAccountMappingSeeder::class)->addMissing();
            }

            app(ProductionRecipeSeeder::class)->addMissing();
        });

        $this->info(sprintf(
            'Added %d account(s), %d product(s) and %d recipe(s). Existing data unchanged.',
            Account::count() - $before[0],
            Product::count() - $before[1],
            ProductionRecipe::count() - $before[2],
        ));

        return self::SUCCESS;
    }
}
