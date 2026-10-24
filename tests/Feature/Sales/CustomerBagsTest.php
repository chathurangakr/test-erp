<?php

namespace Tests\Feature\Sales;

use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Sales\RecordCustomerBagMovement;
use App\Actions\Sales\ReverseCustomerBagMovement;
use App\Actions\Warehouses\SetDefaultWarehouse;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Customer;
use App\Models\CustomerBagMovement;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Inventory\StockQueryService;
use App\Services\Sales\CustomerBagBalances;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Returnable bags lent to customers: they stay the factory's stock while a customer holds them,
 * so giving and returning move them (no journal), and only a loss costs anything. See
 * docs/INVENTORY_RULES.md §18.
 */
class CustomerBagsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $main;

    private Product $bag;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->bag = Product::factory()->create(['code' => 'PP-BAG', 'primary_uom_id' => $nos->id, 'tracks_lots' => false, 'is_returnable' => true]);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->customer = Customer::factory()->create(['name' => 'Lanka Traders']);

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);

        // 100 bags at Rs. 25.
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::OpeningBalance, [
            new PendingInventoryLine($this->bag->id, $this->main->id, InventoryDirection::In, '100.000', unitCost: '25.000000'),
        ], ['transaction_date' => '2026-09-01']);
    }

    private function record(string $kind, string $quantity): CustomerBagMovement
    {
        return app(RecordCustomerBagMovement::class)->handle([
            'movement_date' => '2026-09-15',
            'kind' => $kind,
            'customer_id' => $this->customer->id,
            'product_id' => $this->bag->id,
            'warehouse_id' => $kind === 'LOST' ? null : $this->main->id,
            'quantity' => $quantity,
        ], User::factory()->create()->id);
    }

    private function held(): string
    {
        return app(CustomerBagBalances::class)->held($this->customer->id, $this->bag->id);
    }

    private function inStore(): string
    {
        return app(StockQueryService::class)->quantityForProductInWarehouse($this->bag->id, $this->main->id);
    }

    #[Test]
    public function bags_given_leave_the_store_but_stay_the_factorys_stock(): void
    {
        $this->record('GIVEN', '30');

        $this->assertSame('30.000', $this->held());
        $this->assertSame('70.000', $this->inStore());
        $custody = Warehouse::customerCustody();
        $this->assertSame('30.000', app(StockQueryService::class)->quantityForProductInWarehouse($this->bag->id, $custody->id));

        // Still all ours: company stock and value unchanged, and nothing journalled.
        $this->assertSame('100.000', app(StockQueryService::class)->quantityForProduct($this->bag->id));
        $this->assertSame('2500.000000', app(StockQueryService::class)->valueForProduct($this->bag->id));
        $this->assertSame(0, JournalEntry::count());
    }

    #[Test]
    public function bags_returned_come_back_and_no_more_than_are_held_can_return(): void
    {
        $this->record('GIVEN', '30');
        $this->record('RETURNED', '20');

        $this->assertSame('10.000', $this->held());
        $this->assertSame('90.000', $this->inStore());

        $this->expectException(ValidationException::class);
        $this->record('RETURNED', '11');
    }

    #[Test]
    public function lost_bags_are_written_off_as_a_loss(): void
    {
        $this->record('GIVEN', '30');
        $lost = $this->record('LOST', '4');

        $this->assertSame('26.000', $this->held());
        $this->assertSame('96.000', app(StockQueryService::class)->quantityForProduct($this->bag->id));

        $balance = fn (string $code): string => app(AccountingBalanceService::class)->balance(Account::where('code', $code)->firstOrFail());
        $this->assertSame('100.000000', $balance('6900'));
        $this->assertSame(1, JournalEntry::where('source_type', CustomerBagMovement::class)->where('source_id', $lost->id)->count());

        $this->expectException(ValidationException::class);
        $this->record('LOST', '27');
    }

    #[Test]
    public function bags_already_returned_block_reversing_the_gift_until_the_return_is_reversed(): void
    {
        $given = $this->record('GIVEN', '30');
        $returned = $this->record('RETURNED', '25');

        try {
            app(ReverseCustomerBagMovement::class)->handle($given);
            $this->fail('A gift was reversed although its bags had come back.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        app(ReverseCustomerBagMovement::class)->handle($returned);
        app(ReverseCustomerBagMovement::class)->handle($given);

        $this->assertSame('0.000', $this->held());
        $this->assertSame('100.000', $this->inStore());
    }

    #[Test]
    public function only_a_returnable_item_can_be_lent(): void
    {
        $this->bag->update(['is_returnable' => false]);

        $this->expectException(ValidationException::class);
        $this->record('GIVEN', '1');
    }

    #[Test]
    public function the_customers_place_is_not_a_store(): void
    {
        $this->record('GIVEN', '1');
        $custody = Warehouse::customerCustody();

        // Never offered on a document…
        $this->assertFalse(Warehouse::query()->operational()->whereKey($custody->id)->exists());

        // …refused by the engine for anything but a bag movement…
        try {
            app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
                new PendingInventoryLine($this->bag->id, $custody->id, InventoryDirection::In, '5.000', unitCost: '25.000000'),
            ]);
            $this->fail('Stock was received into the customers\' place.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.warehouse_id', $exception->errors());
        }

        // …and never the default store.
        $this->expectException(ValidationException::class);
        app(SetDefaultWarehouse::class)->handle($custody);
    }

    #[Test]
    public function the_screens_show_who_holds_bags_and_record_them_for_a_permitted_user(): void
    {
        $user = User::factory()->withPermissions(Permission::SalesView, Permission::SalesBagsRecord)->create();

        $this->actingAs($user)->post(route('customer-bags.store'), [
            'movement_date' => '2026-09-15',
            'kind' => 'GIVEN',
            'customer_id' => $this->customer->id,
            'product_id' => $this->bag->id,
            'warehouse_id' => $this->main->id,
            'quantity' => '12',
        ])->assertRedirect();

        $this->actingAs($user)->get(route('customer-bags.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sales/bags/Index')
                ->where('holdings.0.customer_name', 'Lanka Traders')
                ->where('holdings.0.quantity', '12.000'));

        $this->actingAs($user)->get(route('customer-bags.create', ['kind' => 'RETURNED']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kind', 'RETURNED')
                ->has('products', 1)
                // The customers' place is never offered as a store.
                ->has('warehouses', 1));

        $movement = CustomerBagMovement::firstOrFail();
        $this->actingAs($user)->get(route('customer-bags.show', $movement))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('movement.held_now', '12.000'));

        // Recording does not include reversing.
        $this->actingAs($user)->patch(route('customer-bags.reverse', $movement))->assertForbidden();

        $viewer = User::factory()->withPermissions(Permission::SalesView)->create();
        $this->actingAs($viewer)->get(route('customer-bags.create'))->assertForbidden();
    }

    #[Test]
    public function a_loss_names_no_store(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post(route('customer-bags.store'), [
            'movement_date' => '2026-09-15',
            'kind' => 'LOST',
            'customer_id' => $this->customer->id,
            'product_id' => $this->bag->id,
            'warehouse_id' => $this->main->id,
            'quantity' => '1',
        ])->assertSessionHasErrors('warehouse_id');
    }
}
