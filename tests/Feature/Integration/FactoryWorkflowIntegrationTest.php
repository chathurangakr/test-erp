<?php

namespace Tests\Feature\Integration;

use App\Actions\Production\PostCopraProduction;
use App\Actions\Production\PostDehuskingProduction;
use App\Actions\Production\PostHuskChippingProduction;
use App\Actions\Production\ReverseProduction;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\Actions\Sales\PostDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\PricingBasis;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionLine;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Database\Seeders\ProductionRecipeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * End-to-end factory workflow.
 *
 * Every other test suite proves one module in isolation. This one proves the modules agree
 * with each other: that a coconut bought from a supplier can be followed through dehusking,
 * chipping, drying and out to a customer, with the money arriving intact at every hop.
 *
 * The flow it walks is deliberately the real one:
 *
 *   buy coconut → sell some whole → dehusk → sell some dehusked → buy husk
 *   → chip husk → dry copra → sell chips, copra and shell
 *
 * Every figure asserted here is derived from the ledger, never from a stored total. The
 * scenario and its arithmetic are documented in docs/END_TO_END_VALIDATION.md.
 *
 * Runs on the isolated PHPUnit database (SQLite :memory:), never on coconut_erp_dev.
 */
class FactoryWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private UnitOfMeasure $nos;

    private UnitOfMeasure $kg;

    /** @var array<string, Product> keyed by product code */
    private array $products = [];

    private Supplier $supplier;

    private Customer $customer;

    private Warehouse $main;

    private Warehouse $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'name' => 'Numbers', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG', 'name' => 'Kilogram']);

        // The eight products of the seeded Product Master, with their real units.
        foreach ([
            ['COCONUT', 'Whole Coconut', $this->nos, null],
            ['DEHUSKED-COCONUT', 'Dehusked Coconut', $this->nos, null],
            ['RAW-HUSK', 'Raw Coconut Husk', $this->nos, null],
            ['COPRA', 'Copra', $this->kg, null],
            ['HUSK-7C', '7C Husk Chips', $this->kg, null],
            ['HUSK-9C', '9C Husk Chips', $this->kg, null],
            ['HUSK-10C', '10C Husk Chips', $this->kg, null],
            ['RAW-SHELL', 'Raw Coconut Shell', $this->nos, $this->kg],
        ] as [$code, $name, $primary, $secondary]) {
            $this->products[$code] = Product::factory()->create([
                'code' => $code,
                'name' => $name,
                'primary_uom_id' => $primary->id,
                'secondary_uom_id' => $secondary?->id,
            ]);
        }

        $this->supplier = Supplier::factory()->create(['code' => 'TEST-SUPPLIER', 'name' => 'Test Supplier']);
        $this->customer = Customer::factory()->create(['code' => 'TEST-CUSTOMER', 'name' => 'Test Customer']);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN', 'name' => 'Main Warehouse']);
        $this->store = Warehouse::factory()->create(['code' => 'STORE', 'name' => 'Husk Store']);

        $this->seed(ProductionRecipeSeeder::class);
    }

    // ================================================================= helpers

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    private function product(string $code): Product
    {
        return $this->products[$code];
    }

    /**
     * Buy goods: a draft receipt with one line, then posted through purchasing.
     */
    private function receive(
        string $code,
        string $quantity,
        string $price,
        string $transport = '0.000000',
        string $loading = '0.000000',
        ?Warehouse $warehouse = null,
        ?string $supplierLot = null,
    ): GoodsReceipt {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => ($warehouse ?? $this->main)->id,
            'transport_cost' => $transport,
            'loading_cost' => $loading,
        ]);

        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->product($code)->id,
            'quantity' => $quantity,
            'unit_price' => $price,
            'supplier_lot_number' => $supplierLot,
        ]);

        return app(PostGoodsReceipt::class)->handle($receipt->refresh());
    }

    /**
     * Sell goods: a draft delivery, then posted through sales.
     *
     * @param  list<array{code: string, quantity: string, price: string, lot: int, secondary?: string, basis?: PricingBasis}>  $lines
     */
    private function deliver(array $lines, ?Warehouse $warehouse = null): Delivery
    {
        $delivery = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => ($warehouse ?? $this->main)->id,
        ]);

        foreach ($lines as $line) {
            DeliveryLine::factory()->create([
                'delivery_id' => $delivery->id,
                'product_id' => $this->product($line['code'])->id,
                'lot_id' => $line['lot'],
                'quantity' => $line['quantity'],
                'secondary_quantity' => $line['secondary'] ?? null,
                'pricing_basis' => $line['basis'] ?? PricingBasis::Primary,
                'unit_price' => $line['price'],
            ]);
        }

        return app(PostDelivery::class)->handle($delivery->refresh());
    }

    /**
     * A draft production order with one input and the given outputs.
     *
     * Dehusking passes NO outputs: every dehusking quantity is derived from the recipe, so
     * the posting action writes the output rows itself. Chipping and copra pass theirs,
     * because the operator measured them.
     *
     * @param  list<array{code: string, quantity: string, secondary?: string|null}>  $outputs
     */
    private function draftProduction(
        ProductionType $type,
        string $inputCode,
        string $inputQuantity,
        int $lotId,
        array $outputs = [],
    ): ProductionOrder {
        $order = ProductionOrder::factory()->create([
            'production_type' => $type,
            'warehouse_id' => $this->main->id,
        ]);

        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->product($inputCode)->id,
            'lot_id' => $lotId,
            'quantity' => $inputQuantity,
        ]);

        foreach ($outputs as $output) {
            ProductionOutput::factory()->create([
                'production_order_id' => $order->id,
                'product_id' => $this->product($output['code'])->id,
                'quantity' => $output['quantity'],
                'secondary_quantity' => $output['secondary'] ?? null,
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }

        return $order->refresh();
    }

    /** The lot a posted production run created for one of its outputs. */
    private function outputLot(ProductionOrder $order, string $code): int
    {
        return (int) $order->outputs->firstWhere('product_id', $this->product($code)->id)->lot_id;
    }

    private function receiptLot(GoodsReceipt $receipt): int
    {
        return (int) $receipt->lines->first()->lot_id;
    }

    /**
     * The whole factory flow, steps 1 to 8, posted but not asserted.
     *
     * Returned so each test can assert its own theme against the same scenario without the
     * tests depending on one another's state.
     *
     * @return array<string, mixed>
     */
    private function runFactoryFlow(): array
    {
        // ---- Step 1: buy 10,000 whole coconut, with transport and loading.
        $coconutGrn = $this->receive('COCONUT', '10000.000', '100.000000', '20000.000000', '5000.000000');
        $coconutLot = $this->receiptLot($coconutGrn);

        // ---- Step 2: sell 2,000 whole coconut.
        $coconutSale = $this->deliver([
            ['code' => 'COCONUT', 'quantity' => '2000.000', 'price' => '130.000000', 'lot' => $coconutLot],
        ]);

        // ---- Step 3: dehusk 3,000. Both outputs are derived 1:1 by the recipe.
        $dehusking = app(PostDehuskingProduction::class)->handle(
            $this->draftProduction(ProductionType::Dehusking, 'COCONUT', '3000.000', $coconutLot)
        );
        $dehuskedLot = $this->outputLot($dehusking, 'DEHUSKED-COCONUT');
        $huskInternalLot = $this->outputLot($dehusking, 'RAW-HUSK');

        // ---- Step 4: sell 1,000 dehusked coconut.
        $dehuskedSale = $this->deliver([
            ['code' => 'DEHUSKED-COCONUT', 'quantity' => '1000.000', 'price' => '150.000000', 'lot' => $dehuskedLot],
        ]);

        // ---- Step 5: buy 5,000 raw husk at a different cost, into a different warehouse.
        $huskGrn = $this->receive('RAW-HUSK', '5000.000', '20.000000', warehouse: $this->store, supplierLot: 'SUP-HUSK-01');
        $huskPurchasedLot = $this->receiptLot($huskGrn);

        // ---- Step 6: chip 2,000 husk from the internally produced lot.
        $chipping = app(PostHuskChippingProduction::class)->handle(
            $this->draftProduction(ProductionType::HuskChipping, 'RAW-HUSK', '2000.000', $huskInternalLot, [
                ['code' => 'HUSK-7C', 'quantity' => '150.500'],
                ['code' => 'HUSK-9C', 'quantity' => '205.250'],
                ['code' => 'HUSK-10C', 'quantity' => '281.750'],
            ])
        );

        // ---- Step 7: dry 1,000 dehusked coconut into copra and shell.
        $copra = app(PostCopraProduction::class)->handle(
            $this->draftProduction(ProductionType::Copra, 'DEHUSKED-COCONUT', '1000.000', $dehuskedLot, [
                ['code' => 'COPRA', 'quantity' => '190.500'],
                // The draft carries the derived count; the action recalculates it anyway.
                ['code' => 'RAW-SHELL', 'quantity' => '1000.000', 'secondary' => '420.750'],
            ])
        );

        // ---- Step 8: sell finished goods, including shell priced by weight.
        $chipSale = $this->deliver([
            ['code' => 'HUSK-7C', 'quantity' => '50.000', 'price' => '200.000000', 'lot' => $this->outputLot($chipping, 'HUSK-7C')],
        ]);

        $copraSale = $this->deliver([
            ['code' => 'COPRA', 'quantity' => '90.500', 'price' => '500.000000', 'lot' => $this->outputLot($copra, 'COPRA')],
        ]);

        // 208.750 KG is what the 500 shells actually weighed. A pro-rata share of the
        // 420.750 KG would have been 210.375 — the difference is the point.
        $shellSale = $this->deliver([
            [
                'code' => 'RAW-SHELL',
                'quantity' => '500.000',
                'secondary' => '208.750',
                'basis' => PricingBasis::Secondary,
                'price' => '80.000000',
                'lot' => $this->outputLot($copra, 'RAW-SHELL'),
            ],
        ]);

        return [
            'coconut_grn' => $coconutGrn,
            'coconut_lot' => $coconutLot,
            'coconut_sale' => $coconutSale,
            'dehusking' => $dehusking,
            'dehusked_lot' => $dehuskedLot,
            'husk_internal_lot' => $huskInternalLot,
            'dehusked_sale' => $dehuskedSale,
            'husk_grn' => $huskGrn,
            'husk_purchased_lot' => $huskPurchasedLot,
            'chipping' => $chipping,
            'copra' => $copra,
            'chip_sale' => $chipSale,
            'copra_sale' => $copraSale,
            'shell_sale' => $shellSale,
        ];
    }

    private function assertStock(string $code, string $quantity, string $value, string $wac): void
    {
        $id = $this->product($code)->id;

        $this->assertSame($quantity, $this->stock()->quantityForProduct($id), "{$code} quantity");
        $this->assertSame($value, $this->stock()->valueForProduct($id), "{$code} value");
        $this->assertSame($wac, $this->stock()->weightedAverageCostForProduct($id), "{$code} weighted average");
    }

    // ================================================================= steps 1-8

    #[Test]
    public function step_1_buying_coconut_lands_transport_and_loading_into_the_unit_cost(): void
    {
        $receipt = $this->receive('COCONUT', '10000.000', '100.000000', '20000.000000', '5000.000000');
        $line = $receipt->lines->first();

        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->status);
        $this->assertSame('1000000.000000', $line->material_cost);
        $this->assertSame('25000.000000', $line->allocated_landed_cost);
        // (1,000,000 + 25,000) / 10,000 — the landed cost is what reaches inventory.
        $this->assertSame('102.500000', $line->final_unit_cost);

        $this->assertStock('COCONUT', '10000.000', '1025000.000000', '102.500000');

        // A lot exists and traces back to this receipt.
        $lot = InventoryLot::findOrFail($this->receiptLot($receipt));
        $this->assertSame(GoodsReceipt::class, $lot->source_type);
        $this->assertSame($receipt->id, (int) $lot->source_id);
        $this->assertSame('10000.000', $this->stock()->quantityForLot($lot->id));
    }

    #[Test]
    public function step_2_selling_whole_coconut_costs_at_the_weighted_average_and_leaves_it_unchanged(): void
    {
        $receipt = $this->receive('COCONUT', '10000.000', '100.000000', '20000.000000', '5000.000000');

        $sale = $this->deliver([
            ['code' => 'COCONUT', 'quantity' => '2000.000', 'price' => '130.000000', 'lot' => $this->receiptLot($receipt)],
        ]);

        $line = $sale->lines->first();

        $this->assertSame('260000.000000', $line->sales_value);
        $this->assertSame('102.500000', $line->inventory_unit_cost);
        $this->assertSame('205000.000000', $line->cost_of_goods_sold);
        $this->assertSame('55000.000000', $line->grossProfit());

        // Selling at 130 did not drag the average anywhere near it.
        $this->assertStock('COCONUT', '8000.000', '820000.000000', '102.500000');
    }

    #[Test]
    public function step_3_dehusking_converts_one_to_one_and_conserves_value_exactly(): void
    {
        $receipt = $this->receive('COCONUT', '10000.000', '100.000000', '20000.000000', '5000.000000');
        $coconutLot = $this->receiptLot($receipt);

        $this->deliver([
            ['code' => 'COCONUT', 'quantity' => '2000.000', 'price' => '130.000000', 'lot' => $coconutLot],
        ]);

        $dehusking = app(PostDehuskingProduction::class)->handle(
            $this->draftProduction(ProductionType::Dehusking, 'COCONUT', '3000.000', $coconutLot)
        );

        // 3,000 x 102.50
        $this->assertSame('307500.000000', $dehusking->inputs->first()->total_cost);

        $outputs = $dehusking->outputs->keyBy('product_id');
        $dehusked = $outputs[$this->product('DEHUSKED-COCONUT')->id];
        $husk = $outputs[$this->product('RAW-HUSK')->id];

        $this->assertSame('85.000000', $dehusked->allocation_percent);
        $this->assertSame('261375.000000', $dehusked->allocated_cost);
        $this->assertSame('87.125000', $dehusked->unit_cost);

        $this->assertSame('15.000000', $husk->allocation_percent);
        $this->assertSame('46125.000000', $husk->allocated_cost);
        $this->assertSame('15.375000', $husk->unit_cost);

        // Value in equals value out, to the last stored decimal.
        $this->assertSame($dehusking->inputValue(), $dehusking->outputValue());
        $this->assertSame('307500.000000', $dehusking->outputValue());

        $this->assertStock('COCONUT', '5000.000', '512500.000000', '102.500000');
        $this->assertStock('DEHUSKED-COCONUT', '3000.000', '261375.000000', '87.125000');
        $this->assertStock('RAW-HUSK', '3000.000', '46125.000000', '15.375000');
    }

    #[Test]
    public function step_4_selling_dehusked_coconut_uses_its_own_produced_cost(): void
    {
        $flow = $this->runFactoryFlow();
        $line = $flow['dehusked_sale']->lines->first();

        // The cost came from dehusking's allocation, not from the coconut price.
        $this->assertSame('87.125000', $line->inventory_unit_cost);
        $this->assertSame('87125.000000', $line->cost_of_goods_sold);
        $this->assertSame('150000.000000', $line->sales_value);
        $this->assertSame('62875.000000', $line->grossProfit());
    }

    #[Test]
    public function step_5_purchased_and_produced_husk_blend_into_one_company_average(): void
    {
        $flow = $this->runFactoryFlow();

        // Before chipping consumed any: 3,000 internal at 15.375 + 5,000 bought at 20.
        // 46,125 + 100,000 = 146,125 over 8,000 = 18.265625.
        $huskId = $this->product('RAW-HUSK')->id;

        $purchased = $flow['husk_grn']->lines->first();
        $this->assertSame('20.000000', $purchased->final_unit_cost);
        $this->assertSame('0.000000', $purchased->allocated_landed_cost);

        // The two lots stay separate and separately traceable.
        $internal = InventoryLot::findOrFail($flow['husk_internal_lot']);
        $bought = InventoryLot::findOrFail($flow['husk_purchased_lot']);

        $this->assertNotSame($internal->id, $bought->id);
        $this->assertSame(ProductionOrder::class, $internal->source_type);
        $this->assertSame($flow['dehusking']->id, (int) $internal->source_id);
        $this->assertSame(GoodsReceipt::class, $bought->source_type);
        $this->assertSame($flow['husk_grn']->id, (int) $bought->source_id);
        $this->assertSame('SUP-HUSK-01', $bought->supplier_lot_number);

        // 3,000 internal − 2,000 chipped = 1,000; 5,000 purchased untouched.
        $this->assertSame('1000.000', $this->stock()->quantityForLot($internal->id));
        $this->assertSame('5000.000', $this->stock()->quantityForLot($bought->id));

        // The average is company-wide, so it blends across both warehouses.
        $this->assertSame('1000.000', $this->stock()->quantityForProductInWarehouse($huskId, $this->main->id));
        $this->assertSame('5000.000', $this->stock()->quantityForProductInWarehouse($huskId, $this->store->id));
        $this->assertStock('RAW-HUSK', '6000.000', '109593.750000', '18.265625');
    }

    #[Test]
    public function step_6_chipping_uses_the_measured_weights_and_the_configured_split(): void
    {
        $flow = $this->runFactoryFlow();
        $chipping = $flow['chipping'];

        // 2,000 x 18.265625 — the blended average, not either source cost.
        $this->assertSame('18.265625', $chipping->inputs->first()->unit_cost);
        $this->assertSame('36531.250000', $chipping->inputs->first()->total_cost);

        $outputs = $chipping->outputs->keyBy('product_id');
        $c7 = $outputs[$this->product('HUSK-7C')->id];
        $c9 = $outputs[$this->product('HUSK-9C')->id];
        $c10 = $outputs[$this->product('HUSK-10C')->id];

        $this->assertSame('150.500', $c7->quantity);
        $this->assertSame('205.250', $c9->quantity);
        $this->assertSame('281.750', $c10->quantity);

        $this->assertSame('30.000000', $c7->allocation_percent);
        $this->assertSame('10959.375000', $c7->allocated_cost);
        $this->assertSame('72.819767', $c7->unit_cost);

        $this->assertSame('30.000000', $c9->allocation_percent);
        $this->assertSame('10959.375000', $c9->allocated_cost);
        $this->assertSame('53.395250', $c9->unit_cost);

        $this->assertSame('40.000000', $c10->allocation_percent);
        $this->assertSame('14612.500000', $c10->allocated_cost);
        $this->assertSame('51.863354', $c10->unit_cost);

        $this->assertSame($chipping->inputValue(), $chipping->outputValue());

        // Husk left in NOS, chips arrived in KG. Nothing converted one into the other:
        // 2,000 husk produced 637.500 KG, a ratio that exists nowhere in the system.
        $this->assertSame('2000.000', $chipping->inputQuantity());
        foreach ($chipping->outputs as $output) {
            $this->assertNotNull($output->lot_id);
        }
    }

    #[Test]
    public function step_7_copra_derives_the_shell_count_and_measures_both_weights(): void
    {
        $flow = $this->runFactoryFlow();
        $copra = $flow['copra'];

        $this->assertSame('87.125000', $copra->inputs->first()->unit_cost);
        $this->assertSame('87125.000000', $copra->inputs->first()->total_cost);

        $outputs = $copra->outputs->keyBy('product_id');
        $copraOut = $outputs[$this->product('COPRA')->id];
        $shellOut = $outputs[$this->product('RAW-SHELL')->id];

        // Measured by the operator.
        $this->assertSame('190.500', $copraOut->quantity);
        $this->assertNull($copraOut->secondary_quantity);
        $this->assertSame('75.000000', $copraOut->allocation_percent);
        $this->assertSame('65343.750000', $copraOut->allocated_cost);
        $this->assertSame('343.011811', $copraOut->unit_cost);

        // Count derived 1:1 from the coconuts; weight measured.
        $this->assertSame('1000.000', $shellOut->quantity);
        $this->assertSame('420.750', $shellOut->secondary_quantity);
        $this->assertSame('25.000000', $shellOut->allocation_percent);
        $this->assertSame('21781.250000', $shellOut->allocated_cost);
        // Valued per NOS, its primary unit.
        $this->assertSame('21.781250', $shellOut->unit_cost);

        $this->assertSame($copra->inputValue(), $copra->outputValue());
        $this->assertSame('87125.000000', $copra->outputValue());
    }

    #[Test]
    public function step_8_shell_sells_by_weight_while_stock_and_cost_follow_the_count(): void
    {
        $flow = $this->runFactoryFlow();

        $chip = $flow['chip_sale']->lines->first();
        $this->assertSame('10000.000000', $chip->sales_value);
        $this->assertSame('72.819767', $chip->inventory_unit_cost);
        $this->assertSame('3640.988350', $chip->cost_of_goods_sold);

        $copra = $flow['copra_sale']->lines->first();
        $this->assertSame('45250.000000', $copra->sales_value);
        $this->assertSame('343.011811', $copra->inventory_unit_cost);
        $this->assertSame('31042.568896', $copra->cost_of_goods_sold);

        $shell = $flow['shell_sale']->lines->first();
        $this->assertSame(PricingBasis::Secondary, $shell->pricing_basis);

        // Revenue follows the measured weight: 208.750 x 80.
        $this->assertSame('16700.000000', $shell->sales_value);
        // Cost follows the count, because the count is what left stock.
        $this->assertSame('21.781250', $shell->inventory_unit_cost);
        $this->assertSame('10890.625000', $shell->cost_of_goods_sold);
        $this->assertSame('5809.375000', $shell->grossProfit());

        // The weight that left was measured, not apportioned: half the shells by count did
        // not carry half the weight.
        $shellId = $this->product('RAW-SHELL')->id;
        $this->assertSame('500.000', $this->stock()->quantityForProduct($shellId));
        $this->assertSame('212.000', $this->stock()->secondaryQuantityForProduct($shellId));
        $this->assertNotSame('210.375', $shell->secondary_quantity);
    }

    // ================================================================= step 11

    #[Test]
    public function the_scenario_ends_with_the_expected_stock_and_valuation(): void
    {
        $this->runFactoryFlow();

        $this->assertStock('COCONUT', '5000.000', '512500.000000', '102.500000');
        $this->assertStock('DEHUSKED-COCONUT', '1000.000', '87125.000000', '87.125000');
        $this->assertStock('RAW-HUSK', '6000.000', '109593.750000', '18.265625');
        $this->assertStock('HUSK-7C', '100.500', '7318.386650', '72.819768');
        $this->assertStock('HUSK-9C', '205.250', '10959.375000', '53.395250');
        $this->assertStock('HUSK-10C', '281.750', '14612.500000', '51.863354');
        $this->assertStock('COPRA', '100.000', '34301.181104', '343.011811');
        $this->assertStock('RAW-SHELL', '500.000', '10890.625000', '21.781250');

        // Shell keeps its measured weight alongside its count.
        $this->assertSame('212.000', $this->stock()->secondaryQuantityForProduct($this->product('RAW-SHELL')->id));
    }

    #[Test]
    public function every_production_run_conserves_inventory_value(): void
    {
        $flow = $this->runFactoryFlow();

        foreach (['dehusking', 'chipping', 'copra'] as $key) {
            /** @var ProductionOrder $order */
            $order = $flow[$key];

            // Exact at document level: the allocation sums to the consumed value.
            $this->assertSame($order->inputValue(), $order->outputValue(), "{$key} document value");

            $allocated = $order->outputs->reduce(
                fn (string $carry, ProductionOutput $o): string => DecimalMath::add($carry, DecimalMath::of($o->allocated_cost ?? '0')),
                DecimalMath::zero(),
            );

            $this->assertSame($order->inputs->first()->total_cost, DecimalMath::cost($allocated), "{$key} allocation");

            // And exact at LEDGER level too: total_cost is authoritative, so the allocated
            // figures reach the ledger unchanged and nothing is lost re-deriving them from a
            // rounded unit cost. See docs/INVENTORY_RULES.md §8.1.
            $consumedLedger = DecimalMath::cost(
                DecimalMath::of($order->consumptionTransaction->lines->sum(fn ($l): string => $l->total_cost))
            );

            $outputLedger = DecimalMath::zero();

            foreach ($order->outputTransaction->lines as $line) {
                $outputLedger = DecimalMath::add($outputLedger, DecimalMath::of($line->total_cost));
            }

            $this->assertSame(
                $consumedLedger,
                DecimalMath::cost($outputLedger),
                "{$key} ledger value is not conserved exactly",
            );

            $this->assertSame(
                '0.000000',
                DecimalMath::cost(DecimalMath::sub($consumedLedger, $outputLedger)),
                "{$key} left a monetary residue",
            );
        }
    }

    #[Test]
    public function no_product_and_no_lot_ever_goes_negative(): void
    {
        $this->runFactoryFlow();

        foreach ($this->products as $code => $product) {
            $this->assertFalse(
                DecimalMath::isNegative($this->stock()->quantityForProduct($product->id)),
                "{$code} went negative",
            );
        }

        foreach (InventoryLot::all() as $lot) {
            $this->assertFalse(
                DecimalMath::isNegative($this->stock()->quantityForLot($lot->id)),
                "Lot {$lot->lot_number} went negative",
            );
        }

        foreach ($this->stock()->balancesByProductAndWarehouse() as $balance) {
            $this->assertFalse(DecimalMath::isNegative($balance->quantity));
        }
    }

    #[Test]
    public function warehouse_totals_reconcile_to_company_totals(): void
    {
        $this->runFactoryFlow();

        $balances = $this->stock()->balancesByProductAndWarehouse();

        foreach ($this->products as $code => $product) {
            $rows = $balances->where('product_id', $product->id);

            $quantity = $rows->reduce(
                fn (string $carry, object $row): string => DecimalMath::add($carry, DecimalMath::of($row->quantity)),
                DecimalMath::zero(),
            );
            $value = $rows->reduce(
                fn (string $carry, object $row): string => DecimalMath::add($carry, DecimalMath::of($row->value)),
                DecimalMath::zero(),
            );

            $this->assertSame(
                $this->stock()->quantityForProduct($product->id),
                DecimalMath::quantity($quantity),
                "{$code} quantity does not reconcile across warehouses",
            );
            $this->assertSame(
                $this->stock()->valueForProduct($product->id),
                DecimalMath::cost($value),
                "{$code} value does not reconcile across warehouses",
            );
        }
    }

    #[Test]
    public function every_weighted_average_recomputes_from_the_ledger(): void
    {
        $this->runFactoryFlow();

        foreach ($this->products as $code => $product) {
            $quantity = $this->stock()->quantityForProduct($product->id);
            $value = $this->stock()->valueForProduct($product->id);

            $expected = DecimalMath::isZero($quantity)
                ? DecimalMath::zero(DecimalMath::COST_SCALE)
                : DecimalMath::cost(DecimalMath::div($value, $quantity));

            $this->assertSame(
                $expected,
                $this->stock()->weightedAverageCostForProduct($product->id),
                "{$code} weighted average does not follow from quantity and value",
            );
        }
    }

    #[Test]
    public function the_ledger_is_internally_consistent(): void
    {
        $this->runFactoryFlow();

        $this->assertGreaterThan(0, InventoryTransaction::count());

        foreach (InventoryTransaction::with('lines')->get() as $transaction) {
            $this->assertNotEmpty($transaction->lines, "Transaction {$transaction->reference} has no lines");
            $this->assertNotNull($transaction->transaction_date);

            foreach ($transaction->lines as $line) {
                // Quantities are always stored positive; direction alone decides the sign.
                $this->assertTrue(DecimalMath::isPositive(DecimalMath::of($line->quantity)));
                $this->assertContains($line->direction, [InventoryDirection::In, InventoryDirection::Out]);
                $this->assertNotNull($line->warehouse_id);
                $this->assertFalse(DecimalMath::isNegative(DecimalMath::of($line->unit_cost)));

                // total_cost is AUTHORITATIVE, so it is not required to equal
                // quantity x unit_cost. What must hold is that unit_cost is an honest
                // rounded representation of it: the two may differ only by that rounding,
                // at most half a unit-cost tick per unit.
                $this->assertFalse(DecimalMath::isNegative(DecimalMath::of($line->total_cost)));

                $difference = DecimalMath::sub(
                    DecimalMath::of($line->total_cost),
                    DecimalMath::mul(DecimalMath::of($line->quantity), DecimalMath::of($line->unit_cost)),
                );
                $tolerance = DecimalMath::mul(DecimalMath::of($line->quantity), '0.0000005');

                $this->assertTrue(
                    DecimalMath::compare($difference, $tolerance) <= 0
                        && DecimalMath::compare($difference, '-'.$tolerance) >= 0,
                    "Line {$line->id}: total {$line->total_cost} cannot be explained by unit cost {$line->unit_cost}",
                );

                // And the unit cost really is the total divided by the quantity.
                $this->assertSame(
                    DecimalMath::cost(DecimalMath::div(DecimalMath::of($line->total_cost), DecimalMath::of($line->quantity))),
                    $line->unit_cost,
                );
            }
        }
    }

    #[Test]
    public function nothing_in_the_scenario_is_orphaned(): void
    {
        $flow = $this->runFactoryFlow();

        // Every lot belongs to a real product, was created by a real document, and holds
        // at least one ledger movement.
        foreach (InventoryLot::all() as $lot) {
            $this->assertNotNull(Product::find($lot->product_id), "Lot {$lot->lot_number} has no product");
            $this->assertNotNull($lot->source_type, "Lot {$lot->lot_number} has no source document");
            $this->assertNotNull(
                $lot->source_type::find($lot->source_id),
                "Lot {$lot->lot_number} points at a missing {$lot->source_type}",
            );
            $this->assertGreaterThan(
                0,
                InventoryTransactionLine::where('lot_id', $lot->id)->count(),
                "Lot {$lot->lot_number} has no movements",
            );
        }

        // Every document line belongs to a document that exists.
        foreach (ProductionOutput::all() as $output) {
            $this->assertNotNull(ProductionOrder::find($output->production_order_id));
        }
        foreach (ProductionInput::all() as $input) {
            $this->assertNotNull(ProductionOrder::find($input->production_order_id));
        }
        foreach (DeliveryLine::all() as $line) {
            $this->assertNotNull(Delivery::find($line->delivery_id));
        }
        foreach (GoodsReceiptLine::all() as $line) {
            $this->assertNotNull(GoodsReceipt::find($line->goods_receipt_id));
        }
        foreach (SalesOrderLine::all() as $line) {
            $this->assertNotNull(SalesOrder::find($line->sales_order_id));
        }
        foreach (PurchaseOrderLine::all() as $line) {
            $this->assertNotNull(PurchaseOrder::find($line->purchase_order_id));
        }

        // And every ledger line points back at the document that wrote it.
        foreach (InventoryTransaction::all() as $transaction) {
            $this->assertNotNull($transaction->source_type);
            $this->assertNotNull(
                $transaction->source_type::find($transaction->source_id),
                "Transaction {$transaction->reference} points at a missing source",
            );
        }

        unset($flow);
    }

    #[Test]
    public function every_posted_document_points_at_a_real_inventory_transaction(): void
    {
        $flow = $this->runFactoryFlow();

        foreach ([$flow['coconut_grn'], $flow['husk_grn']] as $receipt) {
            $this->assertSame(GoodsReceiptStatus::Posted, $receipt->status);
            $this->assertNotNull(InventoryTransaction::find($receipt->inventory_transaction_id));
            $this->assertSame(
                InventoryTransactionType::PurchaseReceipt,
                $receipt->inventoryTransaction->transaction_type,
            );
        }

        foreach ([$flow['coconut_sale'], $flow['dehusked_sale'], $flow['chip_sale'], $flow['copra_sale'], $flow['shell_sale']] as $delivery) {
            $this->assertSame(DeliveryStatus::Posted, $delivery->status);
            $this->assertNotNull(InventoryTransaction::find($delivery->inventory_transaction_id));
            $this->assertSame(
                InventoryTransactionType::SalesIssue,
                $delivery->inventoryTransaction->transaction_type,
            );
        }

        foreach ([$flow['dehusking'], $flow['chipping'], $flow['copra']] as $order) {
            $this->assertSame(ProductionStatus::Posted, $order->status);
            $this->assertSame(
                InventoryTransactionType::ProductionConsumption,
                $order->consumptionTransaction->transaction_type,
            );
            $this->assertSame(
                InventoryTransactionType::ProductionOutput,
                $order->outputTransaction->transaction_type,
            );
        }
    }

    // ================================================================= step 9

    #[Test]
    public function the_copra_chain_traces_from_supplier_to_customer(): void
    {
        $flow = $this->runFactoryFlow();

        // Supplier → GRN → coconut lot
        $coconutLot = InventoryLot::findOrFail($flow['coconut_lot']);
        $this->assertSame($this->supplier->id, $flow['coconut_grn']->supplier_id);
        $this->assertSame(GoodsReceipt::class, $coconutLot->source_type);
        $this->assertSame($flow['coconut_grn']->id, (int) $coconutLot->source_id);

        // coconut lot → dehusking → dehusked lot
        $this->assertSame($coconutLot->id, $flow['dehusking']->inputs->first()->lot_id);
        $dehuskedLot = InventoryLot::findOrFail($flow['dehusked_lot']);
        $this->assertSame(ProductionOrder::class, $dehuskedLot->source_type);
        $this->assertSame($flow['dehusking']->id, (int) $dehuskedLot->source_id);
        $this->assertStringContainsString($coconutLot->lot_number, (string) $dehuskedLot->notes);

        // dehusked lot → copra run → copra lot and shell lot
        $this->assertSame($dehuskedLot->id, $flow['copra']->inputs->first()->lot_id);

        foreach (['COPRA', 'RAW-SHELL'] as $code) {
            $lot = InventoryLot::findOrFail($this->outputLot($flow['copra'], $code));

            $this->assertSame($this->product($code)->id, $lot->product_id);
            $this->assertSame(ProductionOrder::class, $lot->source_type);
            $this->assertSame($flow['copra']->id, (int) $lot->source_id);
            $this->assertStringContainsString($dehuskedLot->lot_number, (string) $lot->notes);
        }

        // copra lot → delivery, shell lot → delivery
        $this->assertSame(
            $this->outputLot($flow['copra'], 'COPRA'),
            (int) $flow['copra_sale']->lines->first()->lot_id,
        );
        $this->assertSame(
            $this->outputLot($flow['copra'], 'RAW-SHELL'),
            (int) $flow['shell_sale']->lines->first()->lot_id,
        );
        $this->assertSame($this->customer->id, $flow['shell_sale']->customer_id);
    }

    #[Test]
    public function the_husk_chain_traces_from_the_same_coconut_lot_to_the_chip_lots(): void
    {
        $flow = $this->runFactoryFlow();

        $coconutLot = InventoryLot::findOrFail($flow['coconut_lot']);
        $huskLot = InventoryLot::findOrFail($flow['husk_internal_lot']);

        // coconut lot → dehusking → raw husk lot
        $this->assertSame(ProductionOrder::class, $huskLot->source_type);
        $this->assertSame($flow['dehusking']->id, (int) $huskLot->source_id);
        $this->assertStringContainsString($coconutLot->lot_number, (string) $huskLot->notes);

        // raw husk lot → chipping → 7C / 9C / 10C lots
        $this->assertSame($huskLot->id, $flow['chipping']->inputs->first()->lot_id);

        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            $lot = InventoryLot::findOrFail($this->outputLot($flow['chipping'], $code));

            $this->assertSame($this->product($code)->id, $lot->product_id);
            $this->assertSame($flow['chipping']->id, (int) $lot->source_id);
            $this->assertStringContainsString($huskLot->lot_number, (string) $lot->notes);
        }

        // 7C lot → delivery
        $this->assertSame(
            $this->outputLot($flow['chipping'], 'HUSK-7C'),
            (int) $flow['chip_sale']->lines->first()->lot_id,
        );

        // The purchased husk never entered this chain.
        $this->assertNotSame($flow['husk_purchased_lot'], $flow['chipping']->inputs->first()->lot_id);
    }

    // ================================================================= step 10

    #[Test]
    public function a_production_run_cannot_be_reversed_once_its_output_has_been_sold(): void
    {
        $flow = $this->runFactoryFlow();

        $before = [
            'transactions' => InventoryTransaction::count(),
            'coconut' => $this->stock()->quantityForProduct($this->product('COCONUT')->id),
            'dehusked' => $this->stock()->quantityForProduct($this->product('DEHUSKED-COCONUT')->id),
            'husk' => $this->stock()->quantityForProduct($this->product('RAW-HUSK')->id),
            'c7' => $this->stock()->quantityForProduct($this->product('HUSK-7C')->id),
            'copra' => $this->stock()->quantityForProduct($this->product('COPRA')->id),
            'shell' => $this->stock()->quantityForProduct($this->product('RAW-SHELL')->id),
        ];

        foreach (['dehusking', 'chipping', 'copra'] as $key) {
            /** @var ProductionOrder $order */
            $order = $flow[$key];

            try {
                app(ReverseProduction::class)->handle($order);
                $this->fail("Expected the {$key} reversal to be rejected: its output has been consumed.");
            } catch (ValidationException) {
                // expected
            }

            // The document is untouched — no partial reversal, no audit fields written.
            $fresh = $order->fresh();
            $this->assertSame(ProductionStatus::Posted, $fresh->status, "{$key} status");
            $this->assertNull($fresh->reversal_inventory_transaction_id, "{$key} consumption reversal");
            $this->assertNull($fresh->output_reversal_transaction_id, "{$key} output reversal");
            $this->assertNull($fresh->reversed_at, "{$key} reversed_at");
        }

        // And no stock moved at all, in either direction.
        $this->assertSame($before['transactions'], InventoryTransaction::count());
        $this->assertSame($before['coconut'], $this->stock()->quantityForProduct($this->product('COCONUT')->id));
        $this->assertSame($before['dehusked'], $this->stock()->quantityForProduct($this->product('DEHUSKED-COCONUT')->id));
        $this->assertSame($before['husk'], $this->stock()->quantityForProduct($this->product('RAW-HUSK')->id));
        $this->assertSame($before['c7'], $this->stock()->quantityForProduct($this->product('HUSK-7C')->id));
        $this->assertSame($before['copra'], $this->stock()->quantityForProduct($this->product('COPRA')->id));
        $this->assertSame($before['shell'], $this->stock()->quantityForProduct($this->product('RAW-SHELL')->id));
    }

    #[Test]
    public function a_goods_receipt_cannot_be_reversed_once_its_stock_has_been_processed(): void
    {
        $flow = $this->runFactoryFlow();

        // The coconut lot has been sold from and dehusked from; 5,000 remain, but the
        // receipt brought in 10,000, so undoing it would drive the lot negative.
        try {
            app(ReverseGoodsReceipt::class)->handle($flow['coconut_grn']);
            $this->fail('Expected the coconut receipt reversal to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(GoodsReceiptStatus::Posted, $flow['coconut_grn']->fresh()->status);
        $this->assertStock('COCONUT', '5000.000', '512500.000000', '102.500000');
    }

    // ================================================================= step 12

    #[Test]
    public function posted_documents_cannot_be_edited_or_deleted(): void
    {
        $flow = $this->runFactoryFlow();

        foreach ([
            'goods receipt' => $flow['coconut_grn'],
            'delivery' => $flow['coconut_sale'],
            'production order' => $flow['dehusking'],
        ] as $label => $document) {
            try {
                $document->update(['notes' => 'tampered']);
                $this->fail("Expected the {$label} update to be refused.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }

            try {
                $document->delete();
                $this->fail("Expected the {$label} delete to be refused.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Only a draft', $e->getMessage());
            }
        }
    }

    #[Test]
    public function draft_documents_create_no_inventory(): void
    {
        $receipt = $this->receive('COCONUT', '10000.000', '100.000000', '20000.000000', '5000.000000');
        $lot = $this->receiptLot($receipt);

        $transactions = InventoryTransaction::count();
        $lots = InventoryLot::count();

        // A draft of each kind, left unposted.
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => GoodsReceipt::factory()->create([
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->main->id,
            ])->id,
            'product_id' => $this->product('COCONUT')->id,
            'quantity' => '500.000',
            'unit_price' => '100.000000',
        ]);

        DeliveryLine::factory()->create([
            'delivery_id' => Delivery::factory()->create([
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->main->id,
            ])->id,
            'product_id' => $this->product('COCONUT')->id,
            'lot_id' => $lot,
            'quantity' => '500.000',
            'unit_price' => '130.000000',
        ]);

        $this->draftProduction(ProductionType::Dehusking, 'COCONUT', '500.000', $lot);

        // Nothing moved, nothing was lotted, nothing was valued.
        $this->assertSame($transactions, InventoryTransaction::count());
        $this->assertSame($lots, InventoryLot::count());
        $this->assertStock('COCONUT', '10000.000', '1025000.000000', '102.500000');
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->product('DEHUSKED-COCONUT')->id));
    }

    #[Test]
    public function a_reversed_document_stays_visible_and_returns_value_exactly(): void
    {
        $this->runFactoryFlow();

        $before = [
            'quantity' => $this->stock()->quantityForProduct($this->product('COCONUT')->id),
            'value' => $this->stock()->valueForProduct($this->product('COCONUT')->id),
            'wac' => $this->stock()->weightedAverageCostForProduct($this->product('COCONUT')->id),
        ];

        // A fresh receipt whose stock nothing has touched, so it can legitimately be undone.
        $receipt = $this->receive('COCONUT', '100.000', '110.000000');
        $this->assertSame('5100.000', $this->stock()->quantityForProduct($this->product('COCONUT')->id));

        $reversed = app(ReverseGoodsReceipt::class)->handle($receipt);

        // The record survives, marked reversed, still readable, still pointing at both
        // transactions — history is never deleted.
        $this->assertSame(GoodsReceiptStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversed_at);
        $this->assertNotNull(InventoryTransaction::find($reversed->inventory_transaction_id));
        $this->assertNotNull(InventoryTransaction::find($reversed->reversal_inventory_transaction_id));
        $this->assertNotNull(GoodsReceipt::find($receipt->id));
        $this->assertSame('100.000', $reversed->lines->first()->quantity);

        // And the reversal used the original cost, so quantity, value and the average all
        // return to exactly where they were.
        $this->assertSame($before['quantity'], $this->stock()->quantityForProduct($this->product('COCONUT')->id));
        $this->assertSame($before['value'], $this->stock()->valueForProduct($this->product('COCONUT')->id));
        $this->assertSame($before['wac'], $this->stock()->weightedAverageCostForProduct($this->product('COCONUT')->id));
    }

    // ================================================================= scope

    #[Test]
    public function the_whole_flow_creates_no_finance_or_general_ledger_records(): void
    {
        $this->runFactoryFlow();

        foreach ([
            'journals', 'journal_lines', 'general_ledger', 'ledger_accounts',
            'payables', 'receivables', 'payments', 'customer_payments',
            'customer_invoices', 'sales_invoices', 'production_costs', 'labour_costs', 'overheads',
        ] as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "The factory flow must not create a [{$table}] table.",
            );
        }

        // The accounting engine and accounts payable both exist, but this scenario runs with
        // no accounting cutover set, so nothing operational reaches the books — and nothing
        // operational ever creates a supplier invoice or payment on its own.
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalEntryLine::count());
        $this->assertSame(0, SupplierInvoice::count());
        $this->assertSame(0, SupplierPayment::count());
    }
}
