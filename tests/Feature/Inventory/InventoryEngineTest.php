<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Inventory\ReverseInventoryTransaction;
use App\Actions\Inventory\TransferStock;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionLine;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class InventoryEngineTest extends TestCase
{
    use RefreshDatabase;

    private UnitOfMeasure $nos;

    private UnitOfMeasure $kg;

    private Product $product;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->product = Product::factory()->create([
            'code' => 'HUSK-7C',
            'primary_uom_id' => $this->kg->id,
        ]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
    }

    private function poster(): PostInventoryTransaction
    {
        return app(PostInventoryTransaction::class);
    }

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    private function receive(
        string $quantity,
        string $unitCost,
        ?Warehouse $warehouse = null,
        ?Product $product = null,
        ?InventoryLot $lot = null,
        ?string $secondary = null,
    ): InventoryTransaction {
        return $this->poster()->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: ($product ?? $this->product)->id,
                warehouseId: ($warehouse ?? $this->main)->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lot?->id,
                secondaryQuantity: $secondary,
                unitCost: $unitCost,
            ),
        ]);
    }

    private function issue(
        string $quantity,
        ?Warehouse $warehouse = null,
        ?Product $product = null,
        ?InventoryLot $lot = null,
    ): InventoryTransaction {
        return $this->poster()->handle(InventoryTransactionType::SalesIssue, [
            new PendingInventoryLine(
                productId: ($product ?? $this->product)->id,
                warehouseId: ($warehouse ?? $this->main)->id,
                direction: InventoryDirection::Out,
                quantity: $quantity,
                lotId: $lot?->id,
            ),
        ]);
    }

    // ---------------------------------------------------------------- stock basics

    #[Test]
    public function stock_is_zero_before_any_movement(): void
    {
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->product->id));
        $this->assertSame('0.000', $this->stock()->quantityForProductInWarehouse($this->product->id, $this->main->id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($this->product->id));
        $this->assertSame('0.000000', $this->stock()->weightedAverageCostForProduct($this->product->id));
    }

    #[Test]
    public function an_incoming_movement_increases_stock(): void
    {
        $this->receive('100.000', '25.000000');

        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->product->id));
        $this->assertSame('2500.000000', $this->stock()->valueForProduct($this->product->id));
    }

    #[Test]
    public function an_outgoing_movement_decreases_stock(): void
    {
        $this->receive('100.000', '25.000000');
        $this->issue('30.000');

        $this->assertSame('70.000', $this->stock()->quantityForProduct($this->product->id));
        $this->assertSame('1750.000000', $this->stock()->valueForProduct($this->product->id));
    }

    #[Test]
    public function an_outgoing_movement_cannot_exceed_available_stock(): void
    {
        $this->receive('50.000', '10.000000');

        $this->expectException(ValidationException::class);

        $this->issue('50.001');
    }

    #[Test]
    public function a_failed_posting_creates_no_partial_transaction(): void
    {
        $this->receive('10.000', '5.000000');

        $transactionsBefore = InventoryTransaction::count();
        $linesBefore = InventoryTransactionLine::count();

        try {
            // First line is valid, second would go negative. Neither may survive.
            $this->poster()->handle(InventoryTransactionType::AdjustmentOut, [
                new PendingInventoryLine(
                    productId: $this->product->id,
                    warehouseId: $this->main->id,
                    direction: InventoryDirection::Out,
                    quantity: '5.000',
                ),
                new PendingInventoryLine(
                    productId: $this->product->id,
                    warehouseId: $this->main->id,
                    direction: InventoryDirection::Out,
                    quantity: '999.000',
                ),
            ]);

            $this->fail('Expected the posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($transactionsBefore, InventoryTransaction::count());
        $this->assertSame($linesBefore, InventoryTransactionLine::count());
        $this->assertSame('10.000', $this->stock()->quantityForProduct($this->product->id));
    }

    #[Test]
    public function stock_is_tracked_separately_by_warehouse(): void
    {
        $second = Warehouse::factory()->create(['code' => 'WH-2']);

        $this->receive('100.000', '10.000000', $this->main);
        $this->receive('40.000', '10.000000', $second);

        $this->assertSame('100.000', $this->stock()->quantityForProductInWarehouse($this->product->id, $this->main->id));
        $this->assertSame('40.000', $this->stock()->quantityForProductInWarehouse($this->product->id, $second->id));
    }

    #[Test]
    public function the_company_total_equals_the_sum_of_the_warehouses(): void
    {
        $second = Warehouse::factory()->create(['code' => 'WH-2']);

        $this->receive('100.000', '10.000000', $this->main);
        $this->receive('40.000', '10.000000', $second);
        $this->issue('25.000', $this->main);

        $mainQty = $this->stock()->quantityForProductInWarehouse($this->product->id, $this->main->id);
        $secondQty = $this->stock()->quantityForProductInWarehouse($this->product->id, $second->id);

        $this->assertSame('75.000', $mainQty);
        $this->assertSame('40.000', $secondQty);
        $this->assertSame('115.000', $this->stock()->quantityForProduct($this->product->id));
    }

    // ---------------------------------------------------------------- weighted average

    #[Test]
    public function the_weighted_average_cost_after_a_first_receipt_is_the_receipt_price(): void
    {
        $this->receive('100.000', '25.000000');

        $this->assertSame('25.000000', $this->stock()->weightedAverageCostForProduct($this->product->id));
    }

    #[Test]
    public function the_weighted_average_cost_blends_differently_priced_receipts(): void
    {
        // 100 @ 10 = 1000, then 100 @ 20 = 2000. 3000 / 200 = 15.
        $this->receive('100.000', '10.000000');
        $this->receive('100.000', '20.000000');

        $this->assertSame('200.000', $this->stock()->quantityForProduct($this->product->id));
        $this->assertSame('3000.000000', $this->stock()->valueForProduct($this->product->id));
        $this->assertSame('15.000000', $this->stock()->weightedAverageCostForProduct($this->product->id));
    }

    #[Test]
    public function an_issue_uses_the_current_weighted_average_cost(): void
    {
        $this->receive('100.000', '10.000000');
        $this->receive('100.000', '20.000000');

        $transaction = $this->issue('50.000');
        $line = $transaction->lines->first();

        // Issued at the blended 15.00, not at either purchase price.
        $this->assertSame('15.000000', $line->unit_cost);
        $this->assertSame('750.000000', $line->total_cost);

        // The average is unchanged by an issue; only quantity and value fall.
        $this->assertSame('150.000', $this->stock()->quantityForProduct($this->product->id));
        $this->assertSame('2250.000000', $this->stock()->valueForProduct($this->product->id));
        $this->assertSame('15.000000', $this->stock()->weightedAverageCostForProduct($this->product->id));
    }

    #[Test]
    public function a_later_receipt_after_an_issue_reblends_the_average(): void
    {
        $this->receive('100.000', '10.000000');
        $this->issue('50.000');           // 50 left, value 500
        $this->receive('50.000', '30.000000'); // +1500 => 100 @ 2000

        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->product->id));
        $this->assertSame('2000.000000', $this->stock()->valueForProduct($this->product->id));
        $this->assertSame('20.000000', $this->stock()->weightedAverageCostForProduct($this->product->id));
    }

    // ---------------------------------------------------------------- transfers

    #[Test]
    public function a_warehouse_transfer_preserves_global_quantity_value_and_average(): void
    {
        $second = Warehouse::factory()->create(['code' => 'WH-2']);

        $this->receive('100.000', '10.000000');
        $this->receive('100.000', '20.000000');

        $quantityBefore = $this->stock()->quantityForProduct($this->product->id);
        $valueBefore = $this->stock()->valueForProduct($this->product->id);
        $averageBefore = $this->stock()->weightedAverageCostForProduct($this->product->id);

        app(TransferStock::class)->handle($this->product, $this->main, $second, '80.000');

        $this->assertSame($quantityBefore, $this->stock()->quantityForProduct($this->product->id));
        $this->assertSame($valueBefore, $this->stock()->valueForProduct($this->product->id));
        $this->assertSame($averageBefore, $this->stock()->weightedAverageCostForProduct($this->product->id));

        $this->assertSame('120.000', $this->stock()->quantityForProductInWarehouse($this->product->id, $this->main->id));
        $this->assertSame('80.000', $this->stock()->quantityForProductInWarehouse($this->product->id, $second->id));
    }

    #[Test]
    public function a_transfer_creates_one_out_and_one_in_line_at_the_same_cost(): void
    {
        $second = Warehouse::factory()->create(['code' => 'WH-2']);
        $this->receive('100.000', '12.500000');

        $transfer = app(TransferStock::class)->handle($this->product, $this->main, $second, '40.000');

        $out = $transfer->lines->firstWhere('direction', InventoryDirection::Out);
        $in = $transfer->lines->firstWhere('direction', InventoryDirection::In);

        $this->assertNotNull($out);
        $this->assertNotNull($in);
        $this->assertSame($this->main->id, $out->warehouse_id);
        $this->assertSame($second->id, $in->warehouse_id);
        $this->assertSame($out->unit_cost, $in->unit_cost);
        $this->assertSame('12.500000', $in->unit_cost);
        $this->assertSame(InventoryTransactionType::Transfer, $transfer->transaction_type);
    }

    #[Test]
    public function a_transfer_to_the_same_warehouse_is_rejected(): void
    {
        $this->receive('10.000', '1.000000');

        $this->expectException(ValidationException::class);

        app(TransferStock::class)->handle($this->product, $this->main, $this->main, '1.000');
    }

    #[Test]
    public function a_transfer_involving_an_inactive_warehouse_is_rejected(): void
    {
        $inactive = Warehouse::factory()->inactive()->create(['code' => 'WH-OFF']);
        $this->receive('10.000', '1.000000');

        $this->expectException(ValidationException::class);

        app(TransferStock::class)->handle($this->product, $this->main, $inactive, '1.000');
    }

    #[Test]
    public function stock_cannot_be_posted_to_an_inactive_warehouse(): void
    {
        $inactive = Warehouse::factory()->inactive()->create(['code' => 'WH-OFF']);

        $this->expectException(ValidationException::class);

        $this->receive('10.000', '1.000000', $inactive);
    }

    #[Test]
    public function a_transfer_cannot_move_more_than_the_source_warehouse_holds(): void
    {
        $second = Warehouse::factory()->create(['code' => 'WH-2']);
        $this->receive('10.000', '1.000000', $this->main);

        $this->expectException(ValidationException::class);

        app(TransferStock::class)->handle($this->product, $second, $this->main, '1.000');
    }

    // ---------------------------------------------------------------- lots

    #[Test]
    public function lot_stock_is_calculated_correctly(): void
    {
        $lot = InventoryLot::factory()->create(['product_id' => $this->product->id]);

        $this->receive('60.000', '10.000000', lot: $lot);
        $this->issue('20.000', lot: $lot);

        $this->assertSame('40.000', $this->stock()->quantityForLot($lot->id));
        $this->assertSame('40.000', $this->stock()->quantityForProductWarehouseLot($this->product->id, $this->main->id, $lot->id));
    }

    #[Test]
    public function one_product_may_have_multiple_lots(): void
    {
        $first = InventoryLot::factory()->create(['product_id' => $this->product->id]);
        $second = InventoryLot::factory()->create(['product_id' => $this->product->id]);

        $this->receive('30.000', '10.000000', lot: $first);
        $this->receive('70.000', '10.000000', lot: $second);

        $this->assertSame('30.000', $this->stock()->quantityForLot($first->id));
        $this->assertSame('70.000', $this->stock()->quantityForLot($second->id));
        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->product->id));
    }

    #[Test]
    public function a_lot_cannot_be_used_for_a_movement_of_a_different_product(): void
    {
        $otherProduct = Product::factory()->create([
            'code' => 'HUSK-9C',
            'primary_uom_id' => $this->kg->id,
        ]);
        $lot = InventoryLot::factory()->create(['product_id' => $otherProduct->id]);

        $this->expectException(ValidationException::class);

        $this->receive('10.000', '1.000000', lot: $lot);
    }

    #[Test]
    public function a_lot_cannot_be_issued_below_zero_even_when_the_warehouse_has_other_stock(): void
    {
        $lot = InventoryLot::factory()->create(['product_id' => $this->product->id]);

        $this->receive('100.000', '10.000000');           // no lot
        $this->receive('5.000', '10.000000', lot: $lot);  // lot holds 5

        $this->expectException(ValidationException::class);

        $this->issue('10.000', lot: $lot);
    }

    #[Test]
    public function lot_numbers_follow_the_documented_format(): void
    {
        $lot = app(CreateInventoryLot::class)->handle($this->product, [
            'supplier_lot_number' => 'SUP-XYZ-42',
        ]);

        $this->assertMatchesRegularExpression('/^LOT-\d{8}-\d{6}$/', $lot->lot_number);
        $this->assertSame('SUP-XYZ-42', $lot->supplier_lot_number);
        $this->assertSame($this->product->id, $lot->product_id);
    }

    // ---------------------------------------------------------------- secondary quantity

    #[Test]
    public function shell_accepts_a_primary_count_plus_a_secondary_weight(): void
    {
        $shell = Product::factory()->create([
            'code' => 'RAW-SHELL',
            'primary_uom_id' => $this->nos->id,
            'secondary_uom_id' => $this->kg->id,
        ]);

        $this->receive('1000', '2.000000', product: $shell, secondary: '421.750');

        $line = InventoryTransactionLine::where('product_id', $shell->id)->firstOrFail();

        $this->assertSame('1000.000', $line->quantity);
        $this->assertSame('421.750', $line->secondary_quantity);
        $this->assertSame('421.750', $this->stock()->secondaryQuantityForProduct($shell->id));
    }

    #[Test]
    public function shell_does_not_apply_a_fixed_conversion_between_count_and_weight(): void
    {
        $shell = Product::factory()->create([
            'code' => 'RAW-SHELL',
            'primary_uom_id' => $this->nos->id,
            'secondary_uom_id' => $this->kg->id,
        ]);

        // Two receipts of the same count but genuinely different weights.
        $this->receive('1000', '2.000000', product: $shell, secondary: '421.750');
        $this->receive('1000', '2.000000', product: $shell, secondary: '388.400');

        $this->assertSame('2000.000', $this->stock()->quantityForProduct($shell->id));
        // 421.750 + 388.400 — not any multiple of the count.
        $this->assertSame('810.150', $this->stock()->secondaryQuantityForProduct($shell->id));
    }

    #[Test]
    public function a_product_without_a_secondary_unit_rejects_a_secondary_quantity(): void
    {
        $this->expectException(ValidationException::class);

        // $this->product is 7C — KG primary, no secondary unit.
        $this->receive('10.000', '1.000000', secondary: '5.000');
    }

    #[Test]
    public function a_quantity_finer_than_the_unit_allows_is_rejected(): void
    {
        $countable = Product::factory()->create([
            'code' => 'COCONUT',
            'primary_uom_id' => $this->nos->id,
        ]);

        $this->expectException(ValidationException::class);

        // NOS has 0 decimal places — half a coconut is not a thing.
        $this->receive('10.5', '1.000000', product: $countable);
    }

    // ---------------------------------------------------------------- reversal

    #[Test]
    public function a_reversal_creates_opposite_movements(): void
    {
        $receipt = $this->receive('100.000', '10.000000');

        $reversal = app(ReverseInventoryTransaction::class)->handle($receipt);

        $this->assertSame(InventoryTransactionType::Reversal, $reversal->transaction_type);
        $this->assertSame($receipt->id, $reversal->reversal_of_id);
        $this->assertSame(InventoryDirection::Out, $reversal->lines->first()->direction);
        $this->assertSame('100.000', $reversal->lines->first()->quantity);

        // Stock and value are back to nothing.
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->product->id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($this->product->id));
    }

    #[Test]
    public function the_original_transaction_is_unchanged_after_being_reversed(): void
    {
        $receipt = $this->receive('100.000', '10.000000');
        $originalLine = $receipt->lines->first();

        app(ReverseInventoryTransaction::class)->handle($receipt);

        $receipt->refresh();
        $originalLine->refresh();

        $this->assertSame(InventoryTransactionType::PurchaseReceipt, $receipt->transaction_type);
        $this->assertSame(InventoryDirection::In, $originalLine->direction);
        $this->assertSame('100.000', $originalLine->quantity);
        $this->assertSame('10.000000', $originalLine->unit_cost);

        // The only change is the audit pointer to the reversal.
        $this->assertNotNull($receipt->reversed_by_id);
    }

    #[Test]
    public function a_transaction_cannot_be_reversed_twice(): void
    {
        $receipt = $this->receive('100.000', '10.000000');
        app(ReverseInventoryTransaction::class)->handle($receipt);

        $this->expectException(ValidationException::class);

        app(ReverseInventoryTransaction::class)->handle($receipt->refresh());
    }

    #[Test]
    public function a_reversal_cannot_itself_be_reversed(): void
    {
        $receipt = $this->receive('100.000', '10.000000');
        $reversal = app(ReverseInventoryTransaction::class)->handle($receipt);

        $this->expectException(ValidationException::class);

        app(ReverseInventoryTransaction::class)->handle($reversal);
    }

    #[Test]
    public function a_reversal_that_would_cause_negative_stock_is_rejected(): void
    {
        $receipt = $this->receive('100.000', '10.000000');
        $this->issue('80.000'); // only 20 left, so reversing the 100 receipt is impossible

        try {
            app(ReverseInventoryTransaction::class)->handle($receipt);
            $this->fail('Expected the reversal to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        // Nothing was written and the original stands untouched.
        $receipt->refresh();
        $this->assertNull($receipt->reversed_by_id);
        $this->assertSame('20.000', $this->stock()->quantityForProduct($this->product->id));
        $this->assertSame(0, InventoryTransaction::where('transaction_type', InventoryTransactionType::Reversal)->count());
    }

    #[Test]
    public function reversing_a_transfer_returns_the_stock_to_the_source_warehouse(): void
    {
        $second = Warehouse::factory()->create(['code' => 'WH-2']);
        $this->receive('100.000', '10.000000');

        $transfer = app(TransferStock::class)->handle($this->product, $this->main, $second, '40.000');
        app(ReverseInventoryTransaction::class)->handle($transfer);

        $this->assertSame('100.000', $this->stock()->quantityForProductInWarehouse($this->product->id, $this->main->id));
        $this->assertSame('0.000', $this->stock()->quantityForProductInWarehouse($this->product->id, $second->id));
        $this->assertSame('1000.000000', $this->stock()->valueForProduct($this->product->id));
    }

    // ---------------------------------------------------------------- immutability

    #[Test]
    public function a_posted_transaction_cannot_be_updated_or_deleted(): void
    {
        $receipt = $this->receive('10.000', '1.000000');

        try {
            $receipt->update(['notes' => 'tampered']);
            $this->fail('Expected the update to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $receipt->delete();
            $this->fail('Expected the delete to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        $this->assertDatabaseHas('inventory_transactions', ['id' => $receipt->id]);
    }

    #[Test]
    public function a_posted_line_cannot_be_updated_or_deleted(): void
    {
        $receipt = $this->receive('10.000', '1.000000');
        $line = $receipt->lines->first();

        try {
            $line->update(['quantity' => '999.000']);
            $this->fail('Expected the update to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $line->delete();
            $this->fail('Expected the delete to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        $this->assertSame('10.000', $line->fresh()->quantity);
    }

    // ---------------------------------------------------------------- guards

    #[Test]
    public function outgoing_stock_cannot_be_priced_by_the_caller(): void
    {
        $this->receive('100.000', '10.000000');

        $this->expectException(ValidationException::class);

        $this->poster()->handle(InventoryTransactionType::SalesIssue, [
            new PendingInventoryLine(
                productId: $this->product->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::Out,
                quantity: '1.000',
                unitCost: '999.000000',
            ),
        ]);
    }

    #[Test]
    public function a_zero_or_negative_quantity_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->receive('0.000', '1.000000');
    }

    #[Test]
    public function the_products_table_still_has_no_stock_column(): void
    {
        foreach (['current_stock', 'stock', 'stock_quantity', 'quantity', 'qty', 'on_hand', 'balance'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('products', $column),
                "The products table must not have a [{$column}] column — stock comes from the ledger."
            );
        }

        foreach (['current_stock', 'stock', 'quantity', 'on_hand'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('warehouses', $column),
                "The warehouses table must not have a [{$column}] column."
            );
        }
    }

    #[Test]
    public function transaction_references_follow_the_documented_format(): void
    {
        $receipt = $this->receive('10.000', '1.000000');

        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{6}$/', $receipt->reference);
    }
}
