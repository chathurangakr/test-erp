<?php

namespace Tests\Feature\Purchasing;

use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Purchasing\ApprovePurchaseOrder;
use App\Actions\Purchasing\CancelGoodsReceipt;
use App\Actions\Purchasing\CancelPurchaseOrder;
use App\Actions\Purchasing\GeneratePurchasingReference;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionLine;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PurchasingTest extends TestCase
{
    use RefreshDatabase;

    private UnitOfMeasure $nos;

    private UnitOfMeasure $kg;

    private Product $coconut;

    private Product $husk;

    private Supplier $supplier;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->coconut = Product::factory()->create([
            'code' => 'COCONUT',
            'name' => 'Whole Coconut',
            'primary_uom_id' => $this->nos->id,
        ]);

        $this->husk = Product::factory()->create([
            'code' => 'RAW-HUSK',
            'name' => 'Raw Coconut Husk',
            'primary_uom_id' => $this->nos->id,
        ]);

        $this->supplier = Supplier::factory()->create(['code' => 'SUP-001']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
    }

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    private function draftOrder(array $lines = [], array $attributes = []): PurchaseOrder
    {
        $order = PurchaseOrder::factory()->create(array_merge([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
        ], $attributes));

        foreach ($lines ?: [['product' => $this->coconut, 'quantity' => '10000.000', 'price' => '100.000000']] as $line) {
            PurchaseOrderLine::factory()->create([
                'purchase_order_id' => $order->id,
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['price'],
                'line_total' => bcmul($line['quantity'], $line['price'], 6),
            ]);
        }

        return $order->refresh();
    }

    private function draftReceipt(array $lines, array $attributes = []): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create(array_merge([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
        ], $attributes));

        foreach ($lines as $line) {
            GoodsReceiptLine::factory()->create([
                'goods_receipt_id' => $receipt->id,
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['price'],
                'purchase_order_line_id' => $line['po_line_id'] ?? null,
                'supplier_lot_number' => $line['supplier_lot'] ?? null,
                'secondary_quantity' => $line['secondary'] ?? null,
            ]);
        }

        return $receipt->refresh();
    }

    private function postReceipt(GoodsReceipt $receipt): GoodsReceipt
    {
        return app(PostGoodsReceipt::class)->handle($receipt);
    }

    // ------------------------------------------------------------- purchase orders

    #[Test]
    public function a_draft_purchase_order_can_be_created(): void
    {
        $order = $this->draftOrder();

        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame('1000000.000000', $order->materialTotal());
        $this->assertCount(1, $order->lines);
    }

    #[Test]
    public function a_purchase_order_can_be_approved(): void
    {
        $order = $this->draftOrder();

        $approved = app(ApprovePurchaseOrder::class)->handle($order);

        $this->assertSame(PurchaseOrderStatus::Approved, $approved->status);
        $this->assertNotNull($approved->approved_at);
    }

    #[Test]
    public function an_approved_purchase_order_is_no_longer_freely_editable(): void
    {
        $order = app(ApprovePurchaseOrder::class)->handle($this->draftOrder());

        $this->assertFalse($order->status->isEditable());

        // Approving twice is refused — it is a controlled document now.
        $this->expectException(ValidationException::class);
        app(ApprovePurchaseOrder::class)->handle($order);
    }

    #[Test]
    public function an_empty_purchase_order_cannot_be_approved(): void
    {
        $order = PurchaseOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
        ]);

        $this->expectException(ValidationException::class);

        app(ApprovePurchaseOrder::class)->handle($order);
    }

    #[Test]
    public function a_cancelled_purchase_order_cannot_be_received_against(): void
    {
        $order = app(ApprovePurchaseOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();

        app(CancelPurchaseOrder::class)->handle($order);

        $receipt = $this->draftReceipt(
            [['product' => $this->coconut, 'quantity' => '10.000', 'price' => '100.000000', 'po_line_id' => $line->id]],
            ['purchase_order_id' => $order->id],
        );

        $this->expectException(ValidationException::class);

        $this->postReceipt($receipt);
    }

    // ------------------------------------------------------------- goods receipts

    #[Test]
    public function a_direct_goods_receipt_without_a_purchase_order_posts_stock(): void
    {
        $receipt = $this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '500.000', 'price' => '100.000000'],
        ]);

        $posted = $this->postReceipt($receipt);

        $this->assertSame(GoodsReceiptStatus::Posted, $posted->status);
        $this->assertNull($posted->purchase_order_id);
        $this->assertSame('500.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function a_goods_receipt_against_a_purchase_order_updates_the_order(): void
    {
        $order = app(ApprovePurchaseOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();

        $receipt = $this->draftReceipt(
            [['product' => $this->coconut, 'quantity' => '10000.000', 'price' => '100.000000', 'po_line_id' => $line->id]],
            ['purchase_order_id' => $order->id],
        );

        $this->postReceipt($receipt);

        $this->assertSame('10000.000', $line->fresh()->received_quantity);
        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);
    }

    #[Test]
    public function a_partial_receipt_moves_the_order_to_partially_received(): void
    {
        $order = app(ApprovePurchaseOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();

        $receipt = $this->draftReceipt(
            [['product' => $this->coconut, 'quantity' => '4000.000', 'price' => '100.000000', 'po_line_id' => $line->id]],
            ['purchase_order_id' => $order->id],
        );

        $this->postReceipt($receipt);

        $this->assertSame('4000.000', $line->fresh()->received_quantity);
        $this->assertSame('6000.000', $line->fresh()->outstandingQuantity());
        $this->assertSame(PurchaseOrderStatus::PartiallyReceived, $order->fresh()->status);
    }

    #[Test]
    public function more_than_the_ordered_quantity_cannot_be_received(): void
    {
        $order = app(ApprovePurchaseOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();

        $receipt = $this->draftReceipt(
            [['product' => $this->coconut, 'quantity' => '10001.000', 'price' => '100.000000', 'po_line_id' => $line->id]],
            ['purchase_order_id' => $order->id],
        );

        try {
            $this->postReceipt($receipt);
            $this->fail('Expected over-receipt to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('0.000', $line->fresh()->received_quantity);
        $this->assertSame(0, InventoryTransaction::count());
    }

    #[Test]
    public function posting_uses_the_purchase_receipt_transaction_type(): void
    {
        $posted = $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '50.000000'],
        ]));

        $transaction = $posted->inventoryTransaction;

        $this->assertNotNull($transaction);
        $this->assertSame(InventoryTransactionType::PurchaseReceipt, $transaction->transaction_type);
        $this->assertSame(GoodsReceipt::class, $transaction->source_type);
        $this->assertSame($posted->id, (int) $transaction->source_id);
    }

    #[Test]
    public function posting_creates_an_inventory_lot_and_stores_the_supplier_lot_separately(): void
    {
        $posted = $this->postReceipt($this->draftReceipt([
            [
                'product' => $this->coconut,
                'quantity' => '100.000',
                'price' => '50.000000',
                'supplier_lot' => 'SUPPLIER-BATCH-77',
            ],
        ]));

        $line = $posted->lines->first();
        $lot = InventoryLot::findOrFail($line->lot_id);

        $this->assertMatchesRegularExpression('/^LOT-\d{8}-\d{6}$/', $lot->lot_number);
        $this->assertSame($this->coconut->id, $lot->product_id);
        $this->assertSame('SUPPLIER-BATCH-77', $lot->supplier_lot_number);
        $this->assertNotSame($lot->lot_number, $lot->supplier_lot_number);
        $this->assertSame(GoodsReceipt::class, $lot->source_type);

        $this->assertSame('100.000', $this->stock()->quantityForLot($lot->id));
    }

    // ------------------------------------------------------------- validation

    #[Test]
    public function an_inactive_supplier_is_rejected(): void
    {
        $this->supplier->update(['is_active' => false]);

        $receipt = $this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '1.000000'],
        ]);

        $this->expectException(ValidationException::class);

        $this->postReceipt($receipt);
    }

    #[Test]
    public function an_inactive_warehouse_is_rejected(): void
    {
        $inactive = Warehouse::factory()->inactive()->create(['code' => 'WH-OFF']);

        $receipt = $this->draftReceipt(
            [['product' => $this->coconut, 'quantity' => '10.000', 'price' => '1.000000']],
            ['warehouse_id' => $inactive->id],
        );

        $this->expectException(ValidationException::class);

        $this->postReceipt($receipt);
    }

    #[Test]
    public function an_inactive_product_is_rejected_for_an_incoming_purchase(): void
    {
        $this->coconut->update(['is_active' => false]);

        $receipt = $this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '1.000000'],
        ]);

        $this->expectException(ValidationException::class);

        $this->postReceipt($receipt);
    }

    #[Test]
    public function a_zero_quantity_line_is_rejected(): void
    {
        $receipt = $this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '0.000', 'price' => '1.000000'],
        ]);

        $this->expectException(ValidationException::class);

        $this->postReceipt($receipt);
    }

    #[Test]
    public function a_negative_purchase_price_is_rejected(): void
    {
        $receipt = $this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '-1.000000'],
        ]);

        $this->expectException(ValidationException::class);

        $this->postReceipt($receipt);
    }

    // ------------------------------------------------------------- landed cost

    #[Test]
    public function landed_costs_are_added_to_the_inventory_unit_cost(): void
    {
        // The worked example from the specification:
        // 10,000 coconut @ 100 = 1,000,000 material; +20,000 transport +5,000 loading
        // => 1,025,000 / 10,000 = 102.50 per coconut.
        $receipt = $this->draftReceipt(
            [['product' => $this->coconut, 'quantity' => '10000.000', 'price' => '100.000000']],
            ['transport_cost' => '20000.000000', 'loading_cost' => '5000.000000', 'other_cost' => '0.000000'],
        );

        $posted = $this->postReceipt($receipt);
        $line = $posted->lines->first();

        $this->assertSame('1000000.000000', $line->material_cost);
        $this->assertSame('25000.000000', $line->allocated_landed_cost);
        $this->assertSame('102.500000', $line->final_unit_cost);

        // And that landed cost is what reached inventory.
        $this->assertSame('102.500000', $this->stock()->weightedAverageCostForProduct($this->coconut->id));
        $this->assertSame('1025000.000000', $this->stock()->valueForProduct($this->coconut->id));
    }

    #[Test]
    public function landed_costs_are_allocated_across_lines_by_material_value(): void
    {
        // Coconut 1,000 @ 100 = 100,000 (80%); husk 1,000 @ 25 = 25,000 (20%).
        // Landed 10,000 => 8,000 to coconut, 2,000 to husk.
        $receipt = $this->draftReceipt(
            [
                ['product' => $this->coconut, 'quantity' => '1000.000', 'price' => '100.000000'],
                ['product' => $this->husk, 'quantity' => '1000.000', 'price' => '25.000000'],
            ],
            ['transport_cost' => '10000.000000'],
        );

        $posted = $this->postReceipt($receipt);
        $lines = $posted->lines->keyBy('product_id');

        $coconutLine = $lines[$this->coconut->id];
        $huskLine = $lines[$this->husk->id];

        $this->assertSame('8000.000000', $coconutLine->allocated_landed_cost);
        $this->assertSame('2000.000000', $huskLine->allocated_landed_cost);

        $this->assertSame('108.000000', $coconutLine->final_unit_cost);
        $this->assertSame('27.000000', $huskLine->final_unit_cost);

        // The allocation must sum exactly to the header total — no rounding drift.
        $this->assertSame(
            '10000.000000',
            bcadd($coconutLine->allocated_landed_cost, $huskLine->allocated_landed_cost, 6)
        );
    }

    #[Test]
    public function allocation_is_by_value_not_by_quantity(): void
    {
        // Equal quantities but very different values: allocation must follow value.
        $receipt = $this->draftReceipt(
            [
                ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '900.000000'],
                ['product' => $this->husk, 'quantity' => '100.000', 'price' => '100.000000'],
            ],
            ['transport_cost' => '1000.000000'],
        );

        $posted = $this->postReceipt($receipt);
        $lines = $posted->lines->keyBy('product_id');

        // By quantity this would be 500/500. By value it is 900/100.
        $this->assertSame('900.000000', $lines[$this->coconut->id]->allocated_landed_cost);
        $this->assertSame('100.000000', $lines[$this->husk->id]->allocated_landed_cost);
    }

    #[Test]
    public function landed_costs_cannot_be_allocated_when_material_value_is_zero(): void
    {
        $receipt = $this->draftReceipt(
            [['product' => $this->coconut, 'quantity' => '100.000', 'price' => '0.000000']],
            ['transport_cost' => '5000.000000'],
        );

        $this->expectException(ValidationException::class);

        $this->postReceipt($receipt);
    }

    #[Test]
    public function the_weighted_average_updates_after_a_second_purchase_at_a_different_cost(): void
    {
        $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000'],
        ]));

        $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '20.000000'],
        ]));

        $this->assertSame('200.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('3000.000000', $this->stock()->valueForProduct($this->coconut->id));
        $this->assertSame('15.000000', $this->stock()->weightedAverageCostForProduct($this->coconut->id));
    }

    // ------------------------------------------------------------- atomicity

    #[Test]
    public function a_failed_posting_creates_no_inventory_no_lot_and_no_purchase_order_change(): void
    {
        $order = app(ApprovePurchaseOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();

        // Valid first line, invalid second (inactive product) — nothing may survive.
        $this->husk->update(['is_active' => false]);

        $receipt = $this->draftReceipt(
            [
                ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000', 'po_line_id' => $line->id],
                ['product' => $this->husk, 'quantity' => '50.000', 'price' => '5.000000'],
            ],
            ['purchase_order_id' => $order->id],
        );

        try {
            $this->postReceipt($receipt);
            $this->fail('Expected the posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame(0, InventoryTransactionLine::count());
        $this->assertSame(0, InventoryLot::count());
        $this->assertSame('0.000', $line->fresh()->received_quantity);
        $this->assertSame(PurchaseOrderStatus::Approved, $order->fresh()->status);
        $this->assertSame(GoodsReceiptStatus::Draft, $receipt->fresh()->status);
    }

    #[Test]
    public function a_goods_receipt_cannot_be_posted_twice(): void
    {
        $receipt = $this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000'],
        ]);

        $posted = $this->postReceipt($receipt);

        try {
            $this->postReceipt($posted);
            $this->fail('Expected double posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, InventoryTransaction::count());
        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function a_draft_goods_receipt_creates_no_inventory(): void
    {
        $this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000'],
        ]);

        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame(0, InventoryLot::count());
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function a_posted_goods_receipt_is_immutable(): void
    {
        $posted = $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000'],
        ]));

        try {
            $posted->update(['notes' => 'tampered']);
            $this->fail('Expected the update to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $posted->delete();
            $this->fail('Expected the delete to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only a draft', $e->getMessage());
        }

        $this->assertDatabaseHas('goods_receipts', ['id' => $posted->id, 'notes' => null]);
    }

    // ------------------------------------------------------------- reversal

    #[Test]
    public function reversing_a_goods_receipt_creates_an_inventory_reversal(): void
    {
        $posted = $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000'],
        ]));

        $reversed = app(ReverseGoodsReceipt::class)->handle($posted);

        $this->assertSame(GoodsReceiptStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversal_inventory_transaction_id);
        $this->assertNotNull($reversed->reversed_at);

        $reversal = $reversed->reversalInventoryTransaction;
        $this->assertSame(InventoryTransactionType::Reversal, $reversal->transaction_type);
        $this->assertSame($posted->inventory_transaction_id, $reversal->reversal_of_id);
        $this->assertSame(InventoryDirection::Out, $reversal->lines->first()->direction);

        // Stock and value are back to nothing.
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($this->coconut->id));

        // The original receipt still exists with its lines intact.
        $this->assertDatabaseHas('goods_receipts', ['id' => $posted->id]);
        $this->assertSame('100.000', $posted->lines()->first()->quantity);
    }

    #[Test]
    public function reversing_a_goods_receipt_restores_the_purchase_order_quantity(): void
    {
        $order = app(ApprovePurchaseOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();

        $receipt = $this->draftReceipt(
            [['product' => $this->coconut, 'quantity' => '10000.000', 'price' => '100.000000', 'po_line_id' => $line->id]],
            ['purchase_order_id' => $order->id],
        );

        $posted = $this->postReceipt($receipt);
        $this->assertSame(PurchaseOrderStatus::Received, $order->fresh()->status);

        app(ReverseGoodsReceipt::class)->handle($posted);

        $this->assertSame('0.000', $line->fresh()->received_quantity);
        $this->assertSame(PurchaseOrderStatus::Approved, $order->fresh()->status);
    }

    #[Test]
    public function a_goods_receipt_cannot_be_reversed_twice(): void
    {
        $posted = $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000'],
        ]));

        app(ReverseGoodsReceipt::class)->handle($posted);

        $this->expectException(ValidationException::class);

        app(ReverseGoodsReceipt::class)->handle($posted->fresh());
    }

    #[Test]
    public function a_reversal_is_blocked_when_it_would_cause_negative_stock(): void
    {
        $posted = $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000'],
        ]));

        // Issue most of it out, so the receipt can no longer be undone.
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::SalesIssue, [
            new PendingInventoryLine(
                productId: $this->coconut->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::Out,
                quantity: '80.000',
            ),
        ]);

        try {
            app(ReverseGoodsReceipt::class)->handle($posted);
            $this->fail('Expected the reversal to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $fresh = $posted->fresh();
        $this->assertSame(GoodsReceiptStatus::Posted, $fresh->status);
        $this->assertNull($fresh->reversal_inventory_transaction_id);
        $this->assertSame('20.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    // ------------------------------------------------------------- scope guards

    #[Test]
    public function purchasing_creates_no_payable_or_general_ledger_records(): void
    {
        $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000'],
        ]));

        // Finance is a later phase; nothing here may create accounting artefacts.
        foreach (['accounts', 'journals', 'journal_lines', 'supplier_payables', 'payables'] as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "Purchasing must not create a [{$table}] table — finance is a later phase."
            );
        }

        foreach (['balance', 'payable', 'outstanding'] as $column) {
            $this->assertFalse(Schema::hasColumn('suppliers', $column));
        }
    }

    #[Test]
    public function the_products_table_still_has_no_stock_column(): void
    {
        foreach (['current_stock', 'stock', 'stock_quantity', 'quantity', 'on_hand'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('products', $column),
                "The products table must not have a [{$column}] column."
            );
        }
    }

    #[Test]
    public function an_inactive_product_can_still_be_issued_out_by_the_inventory_engine(): void
    {
        $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000'],
        ]));

        $this->coconut->update(['is_active' => false]);

        // Clearing remaining stock of a discontinued product must remain possible.
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::SalesIssue, [
            new PendingInventoryLine(
                productId: $this->coconut->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::Out,
                quantity: '40.000',
            ),
        ]);

        $this->assertSame('60.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function an_inactive_product_cannot_receive_new_stock_from_any_source(): void
    {
        $this->coconut->update(['is_active' => false]);

        foreach ([
            InventoryTransactionType::PurchaseReceipt,
            InventoryTransactionType::ProductionOutput,
            InventoryTransactionType::OpeningBalance,
            InventoryTransactionType::AdjustmentIn,
        ] as $type) {
            try {
                app(PostInventoryTransaction::class)->handle($type, [
                    new PendingInventoryLine(
                        productId: $this->coconut->id,
                        warehouseId: $this->main->id,
                        direction: InventoryDirection::In,
                        quantity: '1.000',
                        unitCost: '1.000000',
                    ),
                ]);

                $this->fail("Expected {$type->value} to be rejected for an inactive product.");
            } catch (ValidationException) {
                // expected
            }
        }

        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function a_negative_quantity_line_is_rejected(): void
    {
        $receipt = $this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '-5.000', 'price' => '1.000000'],
        ]);

        try {
            $this->postReceipt($receipt);
            $this->fail('Expected a negative quantity to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame(GoodsReceiptStatus::Draft, $receipt->fresh()->status);
    }

    #[Test]
    public function other_direct_costs_are_included_in_the_inventory_cost(): void
    {
        // Each header cost on its own must reach inventory, not just transport.
        $receipt = $this->draftReceipt(
            [['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000']],
            ['transport_cost' => '0.000000', 'loading_cost' => '0.000000', 'other_cost' => '500.000000'],
        );

        $posted = $this->postReceipt($receipt);
        $line = $posted->lines->first();

        $this->assertSame('1000.000000', $line->material_cost);
        $this->assertSame('500.000000', $line->allocated_landed_cost);
        $this->assertSame('15.000000', $line->final_unit_cost);
        $this->assertSame('1500.000000', $this->stock()->valueForProduct($this->coconut->id));
    }

    #[Test]
    public function each_header_cost_type_contributes_to_the_landed_total(): void
    {
        $receipt = $this->draftReceipt(
            [['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000']],
            [
                'transport_cost' => '100.000000',
                'loading_cost' => '200.000000',
                'other_cost' => '300.000000',
            ],
        );

        $posted = $this->postReceipt($receipt);

        // 1,000 material + 600 landed = 1,600 over 100 units.
        $this->assertSame('600.000000', $posted->lines->first()->allocated_landed_cost);
        $this->assertSame('16.000000', $posted->lines->first()->final_unit_cost);
    }

    #[Test]
    public function allocated_landed_costs_always_sum_exactly_to_the_header_total(): void
    {
        // Three equal-value lines against a landed cost that does not divide evenly:
        // 100 / 3 = 33.333333... The residual must land somewhere, not vanish.
        $third = Product::factory()->create([
            'code' => 'HUSK-7C',
            'primary_uom_id' => $this->nos->id,
        ]);

        $receipt = $this->draftReceipt(
            [
                ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '10.000000'],
                ['product' => $this->husk, 'quantity' => '10.000', 'price' => '10.000000'],
                ['product' => $third, 'quantity' => '10.000', 'price' => '10.000000'],
            ],
            ['transport_cost' => '100.000000'],
        );

        $posted = $this->postReceipt($receipt);

        $sum = $posted->lines->reduce(
            fn (string $carry, GoodsReceiptLine $line): string => bcadd($carry, $line->allocated_landed_cost, 6),
            '0.000000',
        );

        $this->assertSame('100.000000', $sum, 'Allocations must sum exactly to the header landed cost.');

        // The line totals also reconstruct the grand total exactly: 300 material + 100
        // landed, with the residual carried on the final line (33.333334, not 33.333333).
        $lineTotals = $posted->lines->reduce(
            fn (string $carry, GoodsReceiptLine $line): string => bcadd(
                $carry,
                bcadd($line->material_cost, $line->allocated_landed_cost, 6),
                6,
            ),
            '0.000000',
        );
        $this->assertSame('400.000000', $lineTotals);

        // And the exact landed LINE VALUE is what reaches inventory, not a value rebuilt
        // from the rounded unit cost. 10 x 13.333333 would have been 133.333330; the
        // authoritative total keeps the 133.333333 that was actually calculated, so the
        // three products together hold exactly the 400 that was spent.
        // See docs/INVENTORY_RULES.md §8.1.
        $posted = $posted->fresh(['lines']);

        $this->assertSame('133.333333', $this->stock()->valueForProduct($this->coconut->id));
        $this->assertSame('133.333333', $this->stock()->valueForProduct($this->husk->id));
        $this->assertSame('133.333334', $this->stock()->valueForProduct($third->id));

        $inventoryTotal = collect([$this->coconut, $this->husk, $third])->reduce(
            fn (string $carry, Product $product): string => bcadd(
                $carry,
                $this->stock()->valueForProduct($product->id),
                6,
            ),
            '0.000000',
        );

        $this->assertSame('400.000000', $inventoryTotal, 'No value may be lost to unit-cost rounding.');

        // The unit cost is still the rounded per-unit representation of that total.
        $this->assertSame('13.333333', $posted->lines->first()->final_unit_cost);
        $this->assertSame('13.333333', $posted->inventoryTransaction->lines->first()->unit_cost);
        $this->assertSame('133.333333', $posted->inventoryTransaction->lines->first()->total_cost);
    }

    #[Test]
    public function a_reversal_reuses_the_original_line_unit_cost(): void
    {
        $posted = $this->postReceipt($this->draftReceipt(
            [['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000']],
            ['transport_cost' => '250.000000'],
        ));

        $originalLine = $posted->inventoryTransaction->lines->first();
        $this->assertSame('12.500000', $originalLine->unit_cost);

        // Buy more at a different price so the weighted average moves away from 12.50.
        $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '30.000000'],
        ]));
        $this->assertSame('21.250000', $this->stock()->weightedAverageCostForProduct($this->coconut->id));

        $reversed = app(ReverseGoodsReceipt::class)->handle($posted);
        $reversalLine = $reversed->reversalInventoryTransaction->lines->first();

        // The reversal must use the ORIGINAL 12.50, not the current average of 21.25 —
        // otherwise it would remove more value than the receipt ever added.
        $this->assertSame('12.500000', $reversalLine->unit_cost);
        $this->assertSame('1250.000000', $reversalLine->total_cost);

        // Exactly the second receipt remains: 100 units at 30.
        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('3000.000000', $this->stock()->valueForProduct($this->coconut->id));
        $this->assertSame('30.000000', $this->stock()->weightedAverageCostForProduct($this->coconut->id));
    }

    #[Test]
    public function a_reversed_goods_receipt_is_marked_reversed_not_cancelled(): void
    {
        $posted = $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '1.000000'],
        ]));

        $reversed = app(ReverseGoodsReceipt::class)->handle($posted);

        // REVERSED records that stock really moved and was undone; CANCELLED is reserved
        // for drafts that never reached inventory.
        $this->assertSame(GoodsReceiptStatus::Reversed, $reversed->status);
        $this->assertNotSame(GoodsReceiptStatus::Cancelled, $reversed->status);
        $this->assertTrue($reversed->status->reachedInventory());
    }

    #[Test]
    public function a_draft_goods_receipt_can_be_cancelled_and_creates_no_stock(): void
    {
        $receipt = $this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '10.000000'],
        ]);

        $cancelled = app(CancelGoodsReceipt::class)->handle($receipt);

        $this->assertSame(GoodsReceiptStatus::Cancelled, $cancelled->status);
        $this->assertFalse($cancelled->status->reachedInventory());
        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame(0, InventoryLot::count());
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->coconut->id));

        // The record is kept, never deleted.
        $this->assertDatabaseHas('goods_receipts', ['id' => $receipt->id]);
    }

    #[Test]
    public function a_posted_goods_receipt_cannot_be_cancelled(): void
    {
        $posted = $this->postReceipt($this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '1.000000'],
        ]));

        $this->expectException(ValidationException::class);

        // A posted receipt has real stock behind it — it must be reversed, not cancelled.
        app(CancelGoodsReceipt::class)->handle($posted);
    }

    #[Test]
    public function a_cancelled_goods_receipt_cannot_be_posted(): void
    {
        $receipt = $this->draftReceipt([
            ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '1.000000'],
        ]);

        app(CancelGoodsReceipt::class)->handle($receipt);

        $this->expectException(ValidationException::class);

        $this->postReceipt($receipt->fresh());
    }

    #[Test]
    public function purchasing_document_numbers_follow_the_documented_format(): void
    {
        $reference = app(GeneratePurchasingReference::class);

        $this->assertMatchesRegularExpression('/^PO-\d{8}-\d{6}$/', $reference->forPurchaseOrder());
        $this->assertMatchesRegularExpression('/^GRN-\d{8}-\d{6}$/', $reference->forGoodsReceipt());
    }
}
