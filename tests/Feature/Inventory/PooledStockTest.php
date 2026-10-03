<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\ConsolidatePooledLots;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Sales\PostDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pooled (lot-free) stock: a product with tracks_lots = false is one stock with no lot
 * selection anywhere. Old lots keep their history, and a one-time, value-neutral consolidation
 * moves their balances into the pool. See docs/INVENTORY_RULES.md §15.
 */
class PooledStockTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $main;

    private Product $husk;

    private Product $copra;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->husk = Product::factory()->create(['code' => 'RAW-HUSK', 'primary_uom_id' => $nos->id, 'tracks_lots' => false]);
        $this->copra = Product::factory()->create(['code' => 'COPRA', 'primary_uom_id' => $kg->id]);
    }

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    private function receipt(Product $product, string $quantity, string $price): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create(['warehouse_id' => $this->main->id]);

        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $price,
            'supplier_lot_number' => 'SUP-7',
        ]);

        return app(PostGoodsReceipt::class)->handle($receipt->refresh());
    }

    /** Stock that a pooled product held in a lot before it was pooled. */
    private function oldLotStock(string $quantity, string $unitCost): InventoryLot
    {
        $this->husk->update(['tracks_lots' => true]);
        $lot = app(CreateInventoryLot::class)->handle($this->husk);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->husk->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lot->id,
                unitCost: $unitCost,
            ),
        ]);

        $this->husk->update(['tracks_lots' => false]);

        return $lot;
    }

    #[Test]
    public function a_goods_receipt_of_a_pooled_product_creates_no_lot(): void
    {
        $receipt = $this->receipt($this->husk, '500.000', '12.000000');
        $line = $receipt->lines->first();

        $this->assertNull($line->lot_id);
        $this->assertSame('SUP-7', $line->supplier_lot_number);
        $this->assertSame(0, InventoryLot::count());
        $this->assertSame('500.000', $this->stock()->quantityForProduct($this->husk->id));

        // A lot-tracked product still gets its lot.
        $this->assertNotNull($this->receipt($this->copra, '10.000', '400.000000')->lines->first()->lot_id);
    }

    #[Test]
    public function the_engine_refuses_new_stock_into_a_lot_of_a_pooled_product(): void
    {
        $lot = InventoryLot::factory()->create(['product_id' => $this->husk->id]);

        $this->expectException(ValidationException::class);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->husk->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: '10.000',
                lotId: $lot->id,
                unitCost: '1.000000',
            ),
        ]);
    }

    #[Test]
    public function a_delivery_of_pooled_stock_refuses_a_lot_and_needs_none(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $lot = $this->oldLotStock('100.000', '10.000000');
        $customer = Customer::factory()->create();

        $draft = function (?int $lotId) use ($customer): Delivery {
            $delivery = Delivery::factory()->create(['customer_id' => $customer->id, 'warehouse_id' => $this->main->id]);
            DeliveryLine::factory()->create([
                'delivery_id' => $delivery->id,
                'product_id' => $this->husk->id,
                'lot_id' => $lotId,
                'quantity' => '40.000',
                'unit_price' => '15.000000',
            ]);

            return $delivery->refresh();
        };

        try {
            app(PostDelivery::class)->handle($draft($lot->id));
            $this->fail('Expected a lot on pooled stock to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.lot_id', $e->errors());
        }

        // Without a lot it issues from the warehouse's stock — even stock still in an old lot.
        app(PostDelivery::class)->handle($draft(null));
        $this->assertSame('60.000', $this->stock()->quantityForProduct($this->husk->id));
    }

    #[Test]
    public function consolidation_moves_old_lot_balances_into_the_pool_without_moving_value(): void
    {
        $user = User::factory()->create();
        $lotA = $this->oldLotStock('300.000', '10.000000');
        $lotB = $this->oldLotStock('100.000', '14.000000');
        $this->receipt($this->husk, '200.000', '12.000000');

        $before = [
            $this->stock()->quantityForProduct($this->husk->id),
            $this->stock()->valueForProduct($this->husk->id),
            $this->stock()->weightedAverageCostForProduct($this->husk->id),
        ];

        $transaction = app(ConsolidatePooledLots::class)->handle($user->id);

        $this->assertSame(InventoryTransactionType::LotConsolidation, $transaction->transaction_type);
        $this->assertSame($user->id, $transaction->posted_by);
        // Two lots, two legs each.
        $this->assertCount(4, $transaction->lines);

        // The lots end at zero; their history is still there.
        $this->assertSame('0.000', $this->stock()->quantityForLot($lotA->id));
        $this->assertSame('0.000', $this->stock()->quantityForLot($lotB->id));
        $this->assertNotNull(InventoryLot::find($lotA->id));

        // Quantity, value and average are exactly where they were; no journal is written.
        $this->assertSame($before, [
            $this->stock()->quantityForProduct($this->husk->id),
            $this->stock()->valueForProduct($this->husk->id),
            $this->stock()->weightedAverageCostForProduct($this->husk->id),
        ]);
        $this->assertSame(0, JournalEntry::count());

        // Running it again finds nothing to move.
        $this->assertSame([], app(ConsolidatePooledLots::class)->plan());
        $this->assertNull(app(ConsolidatePooledLots::class)->handle($user->id));
    }

    #[Test]
    public function consolidation_leaves_lot_tracked_products_alone(): void
    {
        $this->receipt($this->copra, '10.000', '400.000000');

        $this->assertSame([], app(ConsolidatePooledLots::class)->plan());
    }

    #[Test]
    public function the_console_command_shows_the_plan_and_writes_only_when_confirmed(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $this->oldLotStock('50.000', '10.000000');
        $transactions = InventoryTransaction::count();

        $this->artisan('inventory:consolidate-pooled-lots', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();
        $this->assertSame($transactions, InventoryTransaction::count());

        $this->artisan('inventory:consolidate-pooled-lots')
            ->expectsOutputToContain('Name an active user')
            ->assertFailed();

        $this->artisan('inventory:consolidate-pooled-lots', ['--user' => $user->email])
            ->expectsConfirmation('Move these balances into lot-free stock?', 'no')
            ->assertSuccessful();
        $this->assertSame($transactions, InventoryTransaction::count());

        $this->artisan('inventory:consolidate-pooled-lots', ['--user' => $user->email, '--force' => true])
            ->assertSuccessful();
        $this->assertSame($transactions + 1, InventoryTransaction::count());
    }
}
