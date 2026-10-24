<?php

namespace Tests\Feature\Masters;

use App\Enums\ProductionType;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\Product;
use App\Models\ProductAccountMapping;
use App\Models\ProductionRecipe;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `masters:add-new` brings what a newer version introduced into an installed database, and
 * changes nothing people already have.
 */
class AddNewMasterDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_update_adds_what_is_missing_and_leaves_everything_else_alone(): void
    {
        $this->seed(DatabaseSeeder::class);

        // An older installation: no bag, no sizes, no stock-loss account or setting, and an
        // owner who renamed a product and an account.
        $large = Product::where('code', 'COCONUT-LARGE')->firstOrFail();
        ProductionRecipe::where('input_product_id', $large->id)->delete();
        ProductAccountMapping::where('product_id', $large->id)->delete();
        $large->delete();
        AccountingSetting::current()->update(['stock_loss_account_id' => null, 'wages_account_id' => Account::where('code', '6800')->value('id')]);
        Account::where('code', '6900')->delete();
        Product::where('code', 'COPRA')->update(['name' => 'Copra (our name)']);
        Account::where('code', '6100')->update(['name' => 'Lorry hire']);

        $this->artisan('masters:add-new')->assertSuccessful();

        $large = Product::where('code', 'COCONUT-LARGE')->firstOrFail();
        $this->assertSame('1310', $large->accountMapping->inventoryAccount->code);
        $this->assertNotNull(ProductionRecipe::forType(ProductionType::Dehusking, $large->id));
        $this->assertSame('6900', AccountingSetting::current()->stockLossAccount->code);

        // Nothing anyone chose is touched.
        $this->assertSame('Copra (our name)', Product::where('code', 'COPRA')->value('name'));
        $this->assertSame('Lorry hire', Account::where('code', '6100')->value('name'));
        $this->assertSame('6800', AccountingSetting::current()->wagesAccount->code);

        // And running it again adds nothing more.
        $counts = [Account::count(), Product::count(), ProductionRecipe::count(), ProductAccountMapping::count()];
        $this->artisan('masters:add-new')->assertSuccessful();
        $this->assertSame($counts, [Account::count(), Product::count(), ProductionRecipe::count(), ProductAccountMapping::count()]);
    }
}
