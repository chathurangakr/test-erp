<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Inventory\ReverseInventoryTransaction;
use App\Actions\Inventory\TransferStock;
use App\Actions\Production\PostCopraProduction;
use App\Actions\Production\PostDehuskingProduction;
use App\Actions\Production\PostHuskChippingProduction;
use App\Actions\Production\ReverseProduction;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionType;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Database\Seeders\ProductionRecipeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inventory total cost is authoritative.
 *
 * A ledger line stores the exact monetary value of the movement. `unit_cost` is only its
 * rounded per-unit representation, so `quantity x unit_cost` may fall a few millionths
 * short — and must never be used to rebuild the value.
 *
 * Before this, a repeating per-unit rate quietly eroded inventory value on every
 * production run: 16,000 allocated over 280 KG came back as 15,999.999960. These tests pin
 * down that it no longer does, and that nothing may write a value the ledger cannot explain.
 *
 * See docs/INVENTORY_RULES.md §8.1.
 */
class AuthoritativeCostTest extends TestCase
{
    use RefreshDatabase;

    private UnitOfMeasure $nos;

    private UnitOfMeasure $kg;

    /** @var array<string, Product> */
    private array $products = [];

    private Supplier $supplier;

    private Warehouse $main;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        foreach ([
            ['COCONUT', $this->nos, null],
            ['DEHUSKED-COCONUT', $this->nos, null],
            ['RAW-HUSK', $this->nos, null],
            ['COPRA', $this->kg, null],
            ['HUSK-7C', $this->kg, null],
            ['HUSK-9C', $this->kg, null],
            ['HUSK-10C', $this->kg, null],
            ['RAW-SHELL', $this->nos, $this->kg],
        ] as [$code, $primary, $secondary]) {
            $this->products[$code] = Product::factory()->create([
                'code' => $code,
                'primary_uom_id' => $primary->id,
                'secondary_uom_id' => $secondary?->id,
            ]);
        }

        $this->supplier = Supplier::factory()->create(['code' => 'SUP-001']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->store = Warehouse::factory()->create(['code' => 'STORE']);

        $this->seed(ProductionRecipeSeeder::class);
    }

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    private function product(string $code): Product
    {
        return $this->products[$code];
    }

    private function lotFor(string $code): InventoryLot
    {
        return app(CreateInventoryLot::class)->handle($this->product($code));
    }

    private function receive(string $code, string $quantity, string $unitCost, ?InventoryLot $lot = null): InventoryTransaction
    {
        return app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->product($code)->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lot?->id,
                unitCost: $unitCost,
            ),
        ]);
    }

    private function issue(string $code, string $quantity, ?InventoryLot $lot = null): InventoryTransaction
    {
        return app(PostInventoryTransaction::class)->handle(InventoryTransactionType::SalesIssue, [
            new PendingInventoryLine(
                productId: $this->product($code)->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::Out,
                quantity: $quantity,
                lotId: $lot?->id,
            ),
        ]);
    }

    /**
     * A balance whose value does NOT divide evenly by its quantity.
     *
     * 10 @ 10.000000 plus 3 @ 13.333333 is 139.999999 over 13 units. The average rounds to
     * 10.769231, and 13 x 10.769231 is 140.000003 — four millionths MORE than the stock is
     * actually worth. Reconstructing value from the unit cost would leave that behind.
     */
    private function seedAwkwardBalance(string $code = 'COCONUT'): void
    {
        $this->receive($code, '10.000', '10.000000');
        $this->receive($code, '3.000', '13.333333');
    }

    // ------------------------------------------------------- purchase receipt

    #[Test]
    public function a_purchase_receipt_stores_its_exact_landed_line_value(): void
    {
        // 10 units, 100 material + 33.333333 landed = 133.333333 over 10 = 13.333333.
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'transport_cost' => '33.333333',
        ]);

        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->product('COCONUT')->id,
            'quantity' => '10.000',
            'unit_price' => '10.000000',
        ]);

        $posted = app(PostGoodsReceipt::class)->handle($receipt->refresh());
        $line = $posted->inventoryTransaction->lines->first();

        $this->assertSame('13.333333', $line->unit_cost);
        // Not 133.333330, which is what 10 x 13.333333 would have produced.
        $this->assertSame('133.333333', $line->total_cost);
        $this->assertSame('133.333333', $this->stock()->valueForProduct($this->product('COCONUT')->id));
    }

    // ------------------------------------------------------- production

    /**
     * The specification's worked example: 40,000 split 30 / 30 / 40 over weights that make
     * the per-KG rate repeat.
     */
    private function postChippingExample(?InventoryLot $lot = null): ProductionOrder
    {
        if ($lot === null) {
            $lot = $this->lotFor('RAW-HUSK');
            $this->receive('RAW-HUSK', '2000.000', '20.000000', $lot);
        }

        $order = ProductionOrder::factory()->huskChipping()->create(['warehouse_id' => $this->main->id]);

        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->product('RAW-HUSK')->id,
            'lot_id' => $lot->id,
            'quantity' => '2000.000',
        ]);

        foreach ([['HUSK-7C', '150.000'], ['HUSK-9C', '200.000'], ['HUSK-10C', '280.000']] as [$code, $quantity]) {
            ProductionOutput::factory()->create([
                'production_order_id' => $order->id,
                'product_id' => $this->product($code)->id,
                'quantity' => $quantity,
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }

        return app(PostHuskChippingProduction::class)->handle($order->refresh());
    }

    #[Test]
    public function a_repeating_unit_rate_does_not_erode_the_allocated_total(): void
    {
        $posted = $this->postChippingExample();

        $c10 = $posted->outputs->firstWhere('product_id', $this->product('HUSK-10C')->id);
        $ledger = $posted->outputTransaction->lines->firstWhere('product_id', $this->product('HUSK-10C')->id);

        // 16,000 / 280 = 57.142857142... The rate repeats; the money does not.
        $this->assertSame('16000.000000', $c10->allocated_cost);
        $this->assertSame('57.142857', $ledger->unit_cost);
        $this->assertSame('16000.000000', $ledger->total_cost);

        // Explicitly NOT the old reconstruction.
        $this->assertNotSame(
            DecimalMath::cost(DecimalMath::mul('280.000', '57.142857')),
            $ledger->total_cost,
        );
        $this->assertSame('15999.999960', DecimalMath::cost(DecimalMath::mul('280.000', '57.142857')));

        $this->assertSame('16000.000000', $this->stock()->valueForProduct($this->product('HUSK-10C')->id));
    }

    #[Test]
    public function husk_chipping_conserves_ledger_value_exactly(): void
    {
        $posted = $this->postChippingExample();

        $this->assertSame('40000.000000', $posted->consumptionTransaction->lines->first()->total_cost);
        $this->assertSame('40000.000000', $this->ledgerTotal($posted->outputTransaction));
    }

    #[Test]
    public function dehusking_conserves_ledger_value_exactly(): void
    {
        $lot = $this->lotFor('COCONUT');
        // A cost that does not divide evenly by the 85 / 15 split.
        $this->receive('COCONUT', '3000.000', '102.505001', $lot);

        $order = ProductionOrder::factory()->create([
            'production_type' => ProductionType::Dehusking,
            'warehouse_id' => $this->main->id,
        ]);
        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->product('COCONUT')->id,
            'lot_id' => $lot->id,
            'quantity' => '1000.000',
        ]);

        $posted = app(PostDehuskingProduction::class)->handle($order->refresh());

        $consumed = $posted->consumptionTransaction->lines->first()->total_cost;

        $this->assertSame('102505.001000', $consumed);
        $this->assertSame($consumed, $this->ledgerTotal($posted->outputTransaction));
    }

    #[Test]
    public function copra_production_conserves_ledger_value_exactly(): void
    {
        $lot = $this->lotFor('DEHUSKED-COCONUT');
        $this->receive('DEHUSKED-COCONUT', '2000.000', '87.125003', $lot);

        $order = ProductionOrder::factory()->copra()->create(['warehouse_id' => $this->main->id]);
        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->product('DEHUSKED-COCONUT')->id,
            'lot_id' => $lot->id,
            'quantity' => '1000.000',
        ]);
        ProductionOutput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->product('COPRA')->id,
            'quantity' => '190.500',
            'allocated_cost' => null,
            'unit_cost' => null,
        ]);
        ProductionOutput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->product('RAW-SHELL')->id,
            'quantity' => '1000.000',
            'secondary_quantity' => '420.750',
            'allocated_cost' => null,
            'unit_cost' => null,
        ]);

        $posted = app(PostCopraProduction::class)->handle($order->refresh());

        $consumed = $posted->consumptionTransaction->lines->first()->total_cost;

        $this->assertSame('87125.003000', $consumed);
        $this->assertSame($consumed, $this->ledgerTotal($posted->outputTransaction));

        // The measured shell weight rode along untouched — valuation stayed on the count.
        $shellLine = $posted->outputTransaction->lines->firstWhere('product_id', $this->product('RAW-SHELL')->id);
        $this->assertSame('420.750', $shellLine->secondary_quantity);
        $this->assertSame('1000.000', $shellLine->quantity);
    }

    private function ledgerTotal(InventoryTransaction $transaction): string
    {
        return DecimalMath::cost($transaction->lines->reduce(
            fn (string $carry, $line): string => DecimalMath::add($carry, DecimalMath::of($line->total_cost)),
            DecimalMath::zero(),
        ));
    }

    // ------------------------------------------------------- representation

    #[Test]
    public function the_unit_cost_is_still_stored_to_six_decimals_and_represents_the_total(): void
    {
        $posted = $this->postChippingExample();

        foreach ($posted->outputTransaction->lines as $line) {
            // Still decimal(20,6): six places, no more and no fewer.
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{6}$/', $line->unit_cost);
            $this->assertMatchesRegularExpression('/^-?\d+\.\d{6}$/', $line->total_cost);

            // The unit cost is exactly the authoritative total divided by the quantity.
            $this->assertSame(
                DecimalMath::cost(DecimalMath::div(DecimalMath::of($line->total_cost), DecimalMath::of($line->quantity))),
                $line->unit_cost,
            );
        }
    }

    #[Test]
    public function a_line_value_that_its_unit_cost_cannot_explain_is_refused(): void
    {
        $line = (new PendingInventoryLine(
            productId: $this->product('COCONUT')->id,
            warehouseId: $this->main->id,
            direction: InventoryDirection::In,
            quantity: '10.000',
        ))->withAuthoritativeCost('10.000000', '999.000000');

        $this->expectException(ValidationException::class);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [$line]);
    }

    #[Test]
    public function a_negative_line_value_is_refused(): void
    {
        $line = (new PendingInventoryLine(
            productId: $this->product('COCONUT')->id,
            warehouseId: $this->main->id,
            direction: InventoryDirection::In,
            quantity: '10.000',
        ))->withAuthoritativeCost('-1.000000', '-10.000000');

        $this->expectException(ValidationException::class);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [$line]);
    }

    // ------------------------------------------------------- stock queries

    #[Test]
    public function stock_value_and_the_weighted_average_derive_from_the_authoritative_total(): void
    {
        $this->seedAwkwardBalance();
        $id = $this->product('COCONUT')->id;

        // Signed SUM(total_cost), not Σ quantity x unit_cost.
        $this->assertSame('13.000', $this->stock()->quantityForProduct($id));
        $this->assertSame('139.999999', $this->stock()->valueForProduct($id));
        $this->assertSame('10.769231', $this->stock()->weightedAverageCostForProduct($id));

        // 13 x 10.769231 = 140.000003 — the average does NOT reproduce the value, and the
        // value is the one that counts.
        $this->assertSame('140.000003', DecimalMath::cost(DecimalMath::mul('13.000', '10.769231')));
    }

    #[Test]
    public function a_partial_issue_still_uses_the_current_weighted_average(): void
    {
        $this->seedAwkwardBalance();
        $id = $this->product('COCONUT')->id;

        $line = $this->issue('COCONUT', '5.000')->lines->first();

        $this->assertSame('10.769231', $line->unit_cost);
        $this->assertSame('53.846155', $line->total_cost);

        $this->assertSame('8.000', $this->stock()->quantityForProduct($id));
        $this->assertSame('86.153844', $this->stock()->valueForProduct($id));
    }

    // ------------------------------------------------------- full depletion

    #[Test]
    public function issuing_the_last_of_a_product_leaves_zero_quantity_and_zero_value(): void
    {
        $this->seedAwkwardBalance();
        $id = $this->product('COCONUT')->id;

        $line = $this->issue('COCONUT', '13.000')->lines->first();

        // The issue takes the whole remaining value, not 13 x the rounded average.
        $this->assertSame('139.999999', $line->total_cost);
        $this->assertSame('10.769231', $line->unit_cost);

        $this->assertSame('0.000', $this->stock()->quantityForProduct($id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($id));
        $this->assertSame('0.000000', $this->stock()->weightedAverageCostForProduct($id));
    }

    #[Test]
    public function depletion_in_stages_also_ends_at_exactly_zero(): void
    {
        $this->seedAwkwardBalance();
        $id = $this->product('COCONUT')->id;

        $this->issue('COCONUT', '5.000');
        $this->assertSame('86.153844', $this->stock()->valueForProduct($id));

        $this->issue('COCONUT', '8.000');

        $this->assertSame('0.000', $this->stock()->quantityForProduct($id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($id));
    }

    #[Test]
    public function depleting_a_production_output_leaves_no_residue_behind(): void
    {
        $posted = $this->postChippingExample();
        $c10 = $this->product('HUSK-10C');
        $lot = InventoryLot::findOrFail($posted->outputs->firstWhere('product_id', $c10->id)->lot_id);

        $this->assertSame('16000.000000', $this->stock()->valueForProduct($c10->id));

        $this->issue('HUSK-10C', '280.000', $lot);

        $this->assertSame('0.000', $this->stock()->quantityForProduct($c10->id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($c10->id));
    }

    // ------------------------------------------------------- transfer

    #[Test]
    public function a_transfer_is_exactly_value_neutral(): void
    {
        $this->seedAwkwardBalance();
        $id = $this->product('COCONUT')->id;

        $valueBefore = $this->stock()->valueForProduct($id);
        $wacBefore = $this->stock()->weightedAverageCostForProduct($id);

        $transfer = app(TransferStock::class)->handle(
            $this->product('COCONUT'),
            $this->main,
            $this->store,
            '13.000',
        );

        $out = $transfer->lines->firstWhere('direction', InventoryDirection::Out);
        $in = $transfer->lines->firstWhere('direction', InventoryDirection::In);

        // One figure, shared. Not two independently rounded ones.
        $this->assertSame($out->total_cost, $in->total_cost);
        $this->assertSame('139.999999', $out->total_cost);

        $this->assertSame('13.000', $this->stock()->quantityForProduct($id));
        $this->assertSame($valueBefore, $this->stock()->valueForProduct($id));
        $this->assertSame($wacBefore, $this->stock()->weightedAverageCostForProduct($id));

        $this->assertSame('0.000', $this->stock()->quantityForProductInWarehouse($id, $this->main->id));
        $this->assertSame('13.000', $this->stock()->quantityForProductInWarehouse($id, $this->store->id));
    }

    #[Test]
    public function a_partial_transfer_is_also_exactly_value_neutral(): void
    {
        $this->seedAwkwardBalance();
        $id = $this->product('COCONUT')->id;

        $valueBefore = $this->stock()->valueForProduct($id);
        $wacBefore = $this->stock()->weightedAverageCostForProduct($id);

        $transfer = app(TransferStock::class)->handle(
            $this->product('COCONUT'),
            $this->main,
            $this->store,
            '4.000',
        );

        $this->assertSame(
            $transfer->lines->firstWhere('direction', InventoryDirection::Out)->total_cost,
            $transfer->lines->firstWhere('direction', InventoryDirection::In)->total_cost,
        );

        $this->assertSame($valueBefore, $this->stock()->valueForProduct($id));
        $this->assertSame($wacBefore, $this->stock()->weightedAverageCostForProduct($id));
    }

    // ------------------------------------------------------- reversal

    #[Test]
    public function a_reversal_removes_the_exact_original_value(): void
    {
        $this->receive('COCONUT', '10.000', '10.000000');

        $awkward = $this->receive('COCONUT', '3.000', '13.333333');
        $originalLine = $awkward->lines->first();

        $this->assertSame('39.999999', $originalLine->total_cost);

        $reversal = app(ReverseInventoryTransaction::class)->handle($awkward);
        $reversalLine = $reversal->lines->first();

        $this->assertSame($originalLine->unit_cost, $reversalLine->unit_cost);
        $this->assertSame($originalLine->total_cost, $reversalLine->total_cost);

        // Exactly the first receipt remains.
        $this->assertSame('10.000', $this->stock()->quantityForProduct($this->product('COCONUT')->id));
        $this->assertSame('100.000000', $this->stock()->valueForProduct($this->product('COCONUT')->id));
    }

    #[Test]
    public function reversing_a_repeating_rate_production_output_restores_value_exactly(): void
    {
        $huskId = $this->product('RAW-HUSK')->id;

        $lot = $this->lotFor('RAW-HUSK');
        $this->receive('RAW-HUSK', '2000.000', '20.000000', $lot);

        $valueBefore = $this->stock()->valueForProduct($huskId);

        $posted = $this->postChippingExample($lot);

        $this->assertSame('0.000000', $this->stock()->valueForProduct($huskId));

        app(ReverseProduction::class)->handle($posted);

        // The husk comes back at exactly what it left at, and no chip value lingers.
        $this->assertSame('2000.000', $this->stock()->quantityForProduct($huskId));
        $this->assertSame($valueBefore, $this->stock()->valueForProduct($huskId));

        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            $this->assertSame('0.000', $this->stock()->quantityForProduct($this->product($code)->id));
            $this->assertSame('0.000000', $this->stock()->valueForProduct($this->product($code)->id));
        }
    }

    // ------------------------------------------------------- security

    #[Test]
    public function an_authoritative_total_cannot_arrive_from_request_shaped_data(): void
    {
        // fromArray is the untrusted path. It has no total-cost key, so a payload carrying
        // one is simply not read.
        $line = PendingInventoryLine::fromArray([
            'product_id' => $this->product('COCONUT')->id,
            'warehouse_id' => $this->main->id,
            'direction' => InventoryDirection::In,
            'quantity' => '10.000',
            'unit_cost' => '10.000000',
            'total_cost' => '999999.000000',
            'system_total_cost' => '999999.000000',
            'system_unit_cost' => '999999.000000',
        ]);

        $this->assertNull($line->systemTotalCost);
        $this->assertNull($line->systemUnitCost);

        $posted = app(PostInventoryTransaction::class)->handle(
            InventoryTransactionType::PurchaseReceipt,
            [$line],
        );

        $this->assertSame('100.000000', $posted->lines->first()->total_cost);
        $this->assertSame('100.000000', $this->stock()->valueForProduct($this->product('COCONUT')->id));
    }

    #[Test]
    public function an_outgoing_line_still_refuses_a_caller_supplied_price(): void
    {
        $this->receive('COCONUT', '10.000', '10.000000');

        $this->expectException(ValidationException::class);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::SalesIssue, [
            new PendingInventoryLine(
                productId: $this->product('COCONUT')->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::Out,
                quantity: '5.000',
                unitCost: '1.000000',
            ),
        ]);
    }
}
