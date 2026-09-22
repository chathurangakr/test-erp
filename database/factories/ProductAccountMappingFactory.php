<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Product;
use App\Models\ProductAccountMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAccountMapping>
 */
class ProductAccountMappingFactory extends Factory
{
    protected $model = ProductAccountMapping::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'inventory_account_id' => Account::factory()->ofType(AccountType::Asset),
            'cogs_account_id' => Account::factory()->ofType(AccountType::CostOfSales),
            'revenue_account_id' => Account::factory()->ofType(AccountType::Revenue),
        ];
    }
}
