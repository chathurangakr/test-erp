<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\Permission;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Stock on hand, the inventory ledger and lots over the API — read only, derived from the
 * same ledger and service as the web screens.
 */
class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    private Product $coconut;

    private Product $shell;

    private Warehouse $main;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => $nos->id]);
        $this->shell = Product::factory()->create(['code' => 'RAW-SHELL', 'primary_uom_id' => $nos->id, 'secondary_uom_id' => $kg->id]);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->store = Warehouse::factory()->create(['code' => 'STORE']);

        Sanctum::actingAs(User::factory()->withPermissions(Permission::InventoryView)->create(), ['mobile-api']);
    }

    private function receive(Product $product, Warehouse $warehouse, string $quantity, string $unitCost, ?int $lotId = null, ?string $secondary = null): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $product->id,
                warehouseId: $warehouse->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lotId,
                secondaryQuantity: $secondary,
                unitCost: $unitCost,
            ),
        ]);
    }

    #[Test]
    public function stock_agrees_with_the_stock_query_service_to_the_last_decimal(): void
    {
        $this->receive($this->coconut, $this->main, '10000.000', '102.500000');
        $this->receive($this->coconut, $this->store, '2000.000', '102.500000');

        $rows = collect($this->getJson(route('api.v1.inventory.stock.index'))->assertOk()->json('data'));
        $main = $rows->firstWhere('warehouse.code', 'MAIN');

        $service = app(StockQueryService::class);
        $this->assertSame($service->quantityForProductInWarehouse($this->coconut->id, $this->main->id), $main['quantity']);
        $this->assertSame('10000.000', $main['quantity']);
        $this->assertSame('1025000.000000', $main['value']);
        $this->assertSame($service->weightedAverageCostForProduct($this->coconut->id), $main['weighted_average_cost']);
        $this->assertIsString($main['value']);
    }

    #[Test]
    public function the_warehouse_filter_narrows_stock_to_one_warehouse(): void
    {
        $this->receive($this->coconut, $this->main, '10000.000', '100.000000');
        $this->receive($this->coconut, $this->store, '2000.000', '100.000000');

        $this->getJson(route('api.v1.inventory.stock.index', ['warehouse_id' => $this->store->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.warehouse.code', 'STORE')
            ->assertJsonPath('data.0.quantity', '2000.000');
    }

    #[Test]
    public function a_dual_unit_product_shows_both_quantities_without_converting(): void
    {
        $this->receive($this->shell, $this->main, '500.000', '20.000000', secondary: '208.750');

        $this->getJson(route('api.v1.inventory.stock.index', ['product_id' => $this->shell->id]))
            ->assertJsonPath('data.0.quantity', '500.000')
            ->assertJsonPath('data.0.secondary_quantity', '208.750')
            ->assertJsonPath('data.0.secondary_uom', 'KG');
    }

    #[Test]
    public function the_ledger_is_paginated_with_metadata_and_a_page_size_cap(): void
    {
        foreach (range(1, 3) as $i) {
            $this->receive($this->coconut, $this->main, '10.000', '100.000000');
        }

        $this->getJson(route('api.v1.inventory.ledger.index', ['per_page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('data.0.direction', 'IN')
            ->assertJsonPath('data.0.quantity', '10.000')
            ->assertJsonPath('data.0.total_cost', '1000.000000')
            ->assertJsonPath('data.0.transaction.type.value', InventoryTransactionType::PurchaseReceipt->value);

        $this->getJson(route('api.v1.inventory.ledger.index', ['per_page' => 1000]))->assertJsonPath('meta.per_page', 100);
    }

    #[Test]
    public function the_ledger_filters_by_warehouse_and_product(): void
    {
        $this->receive($this->coconut, $this->main, '10.000', '100.000000');
        $this->receive($this->coconut, $this->store, '5.000', '100.000000');
        $this->receive($this->shell, $this->main, '1.000', '1.000000', secondary: '0.400');

        $this->getJson(route('api.v1.inventory.ledger.index', ['warehouse_id' => $this->store->id]))->assertJsonCount(1, 'data');
        $this->getJson(route('api.v1.inventory.ledger.index', ['product_id' => $this->shell->id]))->assertJsonCount(1, 'data');
    }

    #[Test]
    public function a_lot_reports_its_on_hand_quantity_per_warehouse(): void
    {
        $lot = InventoryLot::factory()->create(['product_id' => $this->coconut->id, 'lot_number' => 'LOT-A']);
        $this->receive($this->coconut, $this->main, '300.000', '100.000000', $lot->id);
        $this->receive($this->coconut, $this->store, '200.000', '100.000000', $lot->id);

        $this->getJson(route('api.v1.inventory.lots.index'))
            ->assertOk()
            ->assertJsonPath('data.0.lot_number', 'LOT-A')
            ->assertJsonPath('data.0.quantity', '500.000')
            ->assertJsonCount(2, 'data.0.warehouses')
            ->assertJsonStructure(['meta' => ['total', 'per_page']]);
    }

    #[Test]
    public function a_user_without_inventory_view_is_denied_with_json_403(): void
    {
        Sanctum::actingAs(User::factory()->withPermissions(Permission::ProductsView)->create(), ['mobile-api']);

        foreach (['api.v1.inventory.stock.index', 'api.v1.inventory.ledger.index', 'api.v1.inventory.lots.index'] as $route) {
            $this->getJson(route($route))->assertForbidden()->assertJsonStructure(['message']);
        }
    }

    #[Test]
    public function there_is_no_inventory_write_endpoint(): void
    {
        $this->postJson('/api/v1/inventory/stock', [])->assertStatus(405);
        $this->postJson('/api/v1/inventory/ledger', [])->assertStatus(405);
    }
}
