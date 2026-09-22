<?php

namespace Tests\Feature\Production;

use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Production\CancelProduction;
use App\Actions\Production\PostHuskChippingProduction;
use App\Actions\Production\ReverseProduction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\ProductionRecipe;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Database\Seeders\ProductionRecipeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class HuskChippingTest extends TestCase
{
    use RefreshDatabase;

    private UnitOfMeasure $nos;

    private UnitOfMeasure $kg;

    private Product $husk;

    private Product $c7;

    private Product $c9;

    private Product $c10;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        // Dehusking's products must exist too, because both recipes are seeded together.
        Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => $this->nos->id]);
        Product::factory()->create(['code' => 'DEHUSKED-COCONUT', 'primary_uom_id' => $this->nos->id]);
        Product::factory()->create(['code' => 'COPRA', 'primary_uom_id' => $this->kg->id]);
        Product::factory()->create([
            'code' => 'RAW-SHELL',
            'primary_uom_id' => $this->nos->id,
            'secondary_uom_id' => $this->kg->id,
        ]);

        $this->husk = Product::factory()->create(['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary_uom_id' => $this->nos->id]);
        $this->c7 = Product::factory()->create(['code' => 'HUSK-7C', 'name' => '7C', 'primary_uom_id' => $this->kg->id]);
        $this->c9 = Product::factory()->create(['code' => 'HUSK-9C', 'name' => '9C', 'primary_uom_id' => $this->kg->id]);
        $this->c10 = Product::factory()->create(['code' => 'HUSK-10C', 'name' => '10C', 'primary_uom_id' => $this->kg->id]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);

        $this->seed(ProductionRecipeSeeder::class);
    }

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    private function lotFor(Product $product): InventoryLot
    {
        return app(CreateInventoryLot::class)->handle($product);
    }

    /** Put raw husk on hand at a known cost, in a known lot. */
    private function receive(string $quantity, string $unitCost, InventoryLot $lot, ?Product $product = null): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: ($product ?? $this->husk)->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lot->id,
                unitCost: $unitCost,
            ),
        ]);
    }

    /**
     * @param  array<string, string>  $outputs  product code => measured KG
     */
    private function draftOrder(
        string $inputQuantity,
        ?InventoryLot $lot = null,
        array $outputs = [],
        array $attributes = [],
        ?Product $inputProduct = null,
    ): ProductionOrder {
        $order = ProductionOrder::factory()->create(array_merge([
            'production_type' => ProductionType::HuskChipping,
            'warehouse_id' => $this->main->id,
        ], $attributes));

        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => ($inputProduct ?? $this->husk)->id,
            'lot_id' => $lot?->id,
            'quantity' => $inputQuantity,
        ]);

        $products = [
            'HUSK-7C' => $this->c7,
            'HUSK-9C' => $this->c9,
            'HUSK-10C' => $this->c10,
        ];

        foreach ($outputs ?: ['HUSK-7C' => '150.500', 'HUSK-9C' => '205.250', 'HUSK-10C' => '281.750'] as $code => $quantity) {
            ProductionOutput::factory()->create([
                'production_order_id' => $order->id,
                'product_id' => $products[$code]->id,
                'quantity' => $quantity,
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }

        return $order->refresh();
    }

    private function chip(ProductionOrder $order): ProductionOrder
    {
        return app(PostHuskChippingProduction::class)->handle($order);
    }

    // ------------------------------------------------------------- drafts

    #[Test]
    public function a_draft_husk_chipping_order_can_be_created(): void
    {
        $lot = $this->lotFor($this->husk);
        $order = $this->draftOrder('2000.000', $lot);

        $this->assertSame(ProductionStatus::Draft, $order->status);
        $this->assertSame(ProductionType::HuskChipping, $order->production_type);
        $this->assertSame('2000.000', $order->inputQuantity());
        $this->assertCount(3, $order->outputs);
    }

    #[Test]
    public function a_draft_creates_no_inventory_movement(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '19.500000', $lot);

        $before = InventoryTransaction::count();
        $this->draftOrder('2000.000', $lot);

        $this->assertSame($before, InventoryTransaction::count());
        $this->assertSame('6000.000', $this->stock()->quantityForProduct($this->husk->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->c7->id));
    }

    // ------------------------------------------------------------- no fixed conversion

    #[Test]
    public function the_recipe_records_no_fixed_husk_to_kilogram_conversion(): void
    {
        $recipe = ProductionRecipe::forType(ProductionType::HuskChipping);

        $this->assertNotNull($recipe);
        $this->assertSame($this->husk->id, $recipe->input_product_id);
        $this->assertFalse(ProductionType::HuskChipping->hasFixedOutputRatio());
        $this->assertTrue(ProductionType::HuskChipping->hasOperatorEnteredOutputs());

        // A null ratio is what makes "no fixed conversion" structural rather than merely
        // absent: there is no number for anything to derive a weight from.
        foreach ($recipe->outputs as $output) {
            $this->assertNull($output->output_ratio);
        }
    }

    #[Test]
    public function identical_husk_inputs_may_produce_entirely_different_weights(): void
    {
        $first = $this->lotFor($this->husk);
        $second = $this->lotFor($this->husk);
        $this->receive('2000.000', '20.000000', $first);
        $this->receive('2000.000', '20.000000', $second);

        $this->chip($this->draftOrder('2000.000', $first, [
            'HUSK-7C' => '150.500', 'HUSK-9C' => '205.250', 'HUSK-10C' => '281.750',
        ]));

        // The same husk count, deliberately yielding different weights.
        $this->chip($this->draftOrder('2000.000', $second, [
            'HUSK-7C' => '98.000', 'HUSK-9C' => '310.400', 'HUSK-10C' => '150.900',
        ]));

        $this->assertSame('248.500', $this->stock()->quantityForProduct($this->c7->id));
        $this->assertSame('515.650', $this->stock()->quantityForProduct($this->c9->id));
        $this->assertSame('432.650', $this->stock()->quantityForProduct($this->c10->id));
    }

    // ------------------------------------------------------------- the worked example

    #[Test]
    public function processing_2000_husk_moves_stock_by_the_measured_weights(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '19.500000', $lot);

        $this->chip($this->draftOrder('2000.000', $lot, [
            'HUSK-7C' => '150.500', 'HUSK-9C' => '205.250', 'HUSK-10C' => '281.750',
        ]));

        $this->assertSame('4000.000', $this->stock()->quantityForProduct($this->husk->id));
        $this->assertSame('150.500', $this->stock()->quantityForProduct($this->c7->id));
        $this->assertSame('205.250', $this->stock()->quantityForProduct($this->c9->id));
        $this->assertSame('281.750', $this->stock()->quantityForProduct($this->c10->id));
    }

    #[Test]
    public function the_costing_example_allocates_by_configured_percentage(): void
    {
        // The specification's example: 2,000 husk at Rs.20 = Rs.40,000, split 30/30/40.
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $posted = $this->chip($this->draftOrder('2000.000', $lot, [
            'HUSK-7C' => '150.000', 'HUSK-9C' => '200.000', 'HUSK-10C' => '280.000',
        ]));

        $this->assertSame('40000.000000', $posted->inputs->first()->total_cost);

        $outputs = $posted->outputs->keyBy('product_id');

        $this->assertSame('12000.000000', $outputs[$this->c7->id]->allocated_cost);
        $this->assertSame('80.000000', $outputs[$this->c7->id]->unit_cost);

        $this->assertSame('12000.000000', $outputs[$this->c9->id]->allocated_cost);
        $this->assertSame('60.000000', $outputs[$this->c9->id]->unit_cost);

        $this->assertSame('16000.000000', $outputs[$this->c10->id]->allocated_cost);
        // 16,000 / 280 = 57.142857…
        $this->assertSame('57.142857', $outputs[$this->c10->id]->unit_cost);

        $this->assertSame('40000.000000', $posted->outputValue());
    }

    #[Test]
    public function production_neither_creates_nor_destroys_inventory_value(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '19.500000', $lot);

        $valueBefore = $this->stock()->valueForProduct($this->husk->id);

        $posted = $this->chip($this->draftOrder('2000.000', $lot, [
            'HUSK-7C' => '150.500', 'HUSK-9C' => '205.250', 'HUSK-10C' => '281.750',
        ]));

        $this->assertSame($posted->inputValue(), $posted->outputValue());
        $this->assertSame('39000.000000', $posted->outputValue());

        $totalAfter = collect([$this->husk, $this->c7, $this->c9, $this->c10])
            ->reduce(fn (string $carry, Product $p): string => bcadd(
                $carry,
                $this->stock()->valueForProduct($p->id),
                6,
            ), '0.000000');

        // The document conserves value exactly. The ledger stores a *unit* cost rounded to
        // six decimals, so the re-multiplied stock value carries a residue bounded by half a
        // unit-cost tick per output line. See docs/BUSINESS_RULES.md §13.
        $residue = bcsub($valueBefore, $totalAfter, 6);
        $bound = bcmul('637.500', '0.0000005', 6); // total output KG x half a tick

        $this->assertLessThanOrEqual(0, bccomp(bcsub('0', $bound, 6), $residue, 6));
        $this->assertLessThanOrEqual(0, bccomp($residue, $bound, 6));
    }

    #[Test]
    public function the_rounding_remainder_is_absorbed_so_the_total_is_exact(): void
    {
        // A consumed value that 30/30/40 cannot split cleanly.
        $lot = $this->lotFor($this->husk);
        $this->receive('1000.000', '33.333333', $lot);

        $posted = $this->chip($this->draftOrder('7.000', $lot, [
            'HUSK-7C' => '1.100', 'HUSK-9C' => '2.300', 'HUSK-10C' => '3.700',
        ]));

        $consumed = $posted->inputs->first()->total_cost;
        $allocated = $posted->outputs->reduce(
            fn (string $carry, ProductionOutput $o): string => bcadd($carry, $o->allocated_cost, 6),
            '0.000000',
        );

        $this->assertSame($consumed, $allocated);
    }

    // ------------------------------------------------------------- zero-output redistribution

    #[Test]
    public function a_zero_output_receives_no_value_and_its_share_is_redistributed(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        // 9C produced nothing. Configured 30/30/40 becomes 30/70 and 40/70.
        $posted = $this->chip($this->draftOrder('2000.000', $lot, [
            'HUSK-7C' => '100.000', 'HUSK-9C' => '0.000', 'HUSK-10C' => '200.000',
        ]));

        $outputs = $posted->outputs->keyBy('product_id');

        $this->assertSame('42.857143', $outputs[$this->c7->id]->allocation_percent);
        $this->assertSame('57.142857', $outputs[$this->c10->id]->allocation_percent);
        $this->assertSame('0.000000', $outputs[$this->c9->id]->allocation_percent);

        // 40,000 × 30/70 = 17,142.857143; 10C absorbs the remainder.
        $this->assertSame('17142.857143', $outputs[$this->c7->id]->allocated_cost);
        $this->assertSame('22857.142857', $outputs[$this->c10->id]->allocated_cost);
        $this->assertSame('0.000000', $outputs[$this->c9->id]->allocated_cost);

        // Still exact.
        $this->assertSame('40000.000000', $posted->outputValue());

        // 9C gained no stock and no value.
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->c9->id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($this->c9->id));
    }

    #[Test]
    public function a_zero_output_creates_no_lot(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $lotsBefore = InventoryLot::count();

        $posted = $this->chip($this->draftOrder('2000.000', $lot, [
            'HUSK-7C' => '100.000', 'HUSK-9C' => '0.000', 'HUSK-10C' => '200.000',
        ]));

        // Two lots, not three.
        $this->assertSame($lotsBefore + 2, InventoryLot::count());

        $outputs = $posted->outputs->keyBy('product_id');
        $this->assertNotNull($outputs[$this->c7->id]->lot_id);
        $this->assertNotNull($outputs[$this->c10->id]->lot_id);
        $this->assertNull($outputs[$this->c9->id]->lot_id);

        $this->assertSame(0, InventoryLot::where('product_id', $this->c9->id)->count());
    }

    #[Test]
    public function a_batch_with_every_output_zero_is_rejected(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        try {
            $this->chip($this->draftOrder('2000.000', $lot, [
                'HUSK-7C' => '0.000', 'HUSK-9C' => '0.000', 'HUSK-10C' => '0.000',
            ]));
            $this->fail('Expected an all-zero batch to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('6000.000', $this->stock()->quantityForProduct($this->husk->id));
        $this->assertSame(1, InventoryTransaction::count());
    }

    #[Test]
    public function a_negative_output_is_rejected(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $this->expectException(ValidationException::class);

        $this->chip($this->draftOrder('2000.000', $lot, [
            'HUSK-7C' => '-10.000', 'HUSK-9C' => '100.000', 'HUSK-10C' => '100.000',
        ]));
    }

    // ------------------------------------------------------------- input validation

    #[Test]
    public function an_input_lot_is_required(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $this->expectException(ValidationException::class);

        $this->chip($this->draftOrder('2000.000', null));
    }

    #[Test]
    public function a_lot_belonging_to_another_product_is_rejected(): void
    {
        $huskLot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $huskLot);

        $chipLot = $this->lotFor($this->c7);

        $this->expectException(ValidationException::class);

        $this->chip($this->draftOrder('2000.000', $chipLot));
    }

    #[Test]
    public function an_input_product_other_than_raw_husk_is_rejected(): void
    {
        $chipLot = $this->lotFor($this->c7);
        $this->receive('500.000', '80.000000', $chipLot, $this->c7);

        $this->expectException(ValidationException::class);

        $this->chip($this->draftOrder('100.000', $chipLot, inputProduct: $this->c7));
    }

    #[Test]
    public function insufficient_husk_stock_is_rejected(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('100.000', '20.000000', $lot);

        try {
            $this->chip($this->draftOrder('101.000', $lot));
            $this->fail('Expected insufficient stock to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->husk->id));
    }

    #[Test]
    public function a_zero_input_quantity_is_rejected(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $this->expectException(ValidationException::class);

        $this->chip($this->draftOrder('0.000', $lot));
    }

    #[Test]
    public function a_negative_input_quantity_is_rejected(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $this->expectException(ValidationException::class);

        $this->chip($this->draftOrder('-100.000', $lot));
    }

    #[Test]
    public function an_inactive_warehouse_is_rejected(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $inactive = Warehouse::factory()->inactive()->create(['code' => 'WH-OFF']);

        $this->expectException(ValidationException::class);

        $this->chip($this->draftOrder('100.000', $lot, [], ['warehouse_id' => $inactive->id]));
    }

    #[Test]
    public function an_output_product_outside_the_recipe_is_rejected(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $order = $this->draftOrder('2000.000', $lot);
        ProductionOutput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->husk->id, // not a chipping output
            'quantity' => '10.000',
        ]);

        $this->expectException(ValidationException::class);

        $this->chip($order->refresh());
    }

    #[Test]
    public function a_recipe_that_does_not_allocate_one_hundred_percent_is_rejected(): void
    {
        $recipe = ProductionRecipe::forType(ProductionType::HuskChipping);
        $recipe->outputs()->where('product_id', $this->c10->id)->update(['allocation_percent' => '10.000000']);

        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        try {
            $this->chip($this->draftOrder('2000.000', $lot));
            $this->fail('Expected a 70% recipe to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('6000.000', $this->stock()->quantityForProduct($this->husk->id));
    }

    #[Test]
    public function the_configured_allocation_totals_one_hundred_percent(): void
    {
        $recipe = ProductionRecipe::forType(ProductionType::HuskChipping);

        $this->assertSame('100.000000', $recipe->allocationTotal());

        $percentages = $recipe->outputs->pluck('allocation_percent', 'product_id');
        $this->assertSame('30.000000', $percentages[$this->c7->id]);
        $this->assertSame('30.000000', $percentages[$this->c9->id]);
        $this->assertSame('40.000000', $percentages[$this->c10->id]);
    }

    // ------------------------------------------------------------- waste

    #[Test]
    public function waste_is_recorded_as_information_and_creates_no_inventory(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $order = $this->draftOrder('2000.000', $lot, [
            'HUSK-7C' => '150.000', 'HUSK-9C' => '200.000', 'HUSK-10C' => '280.000',
        ]);
        $order->update(['waste_quantity' => '42.500', 'waste_uom_id' => $this->kg->id]);

        $posted = $this->chip($order->refresh());

        $this->assertSame('42.500', $posted->waste_quantity);
        $this->assertSame($this->kg->id, $posted->waste_uom_id);

        // Waste created no product, no lot and no ledger line.
        $this->assertSame(3, $posted->outputTransaction->lines->count());
        $this->assertSame(0, InventoryLot::whereNotIn('product_id', [
            $this->husk->id, $this->c7->id, $this->c9->id, $this->c10->id,
        ])->count());

        // And it took no value: the saleable outputs still absorb the whole consumed cost.
        $this->assertSame($posted->inputValue(), $posted->outputValue());
    }

    #[Test]
    public function waste_is_optional(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $posted = $this->chip($this->draftOrder('2000.000', $lot));

        $this->assertNull($posted->waste_quantity);
        $this->assertNull($posted->waste_uom_id);
        $this->assertSame(ProductionStatus::Posted, $posted->status);
    }

    // ------------------------------------------------------------- lots and traceability

    #[Test]
    public function posting_creates_a_lot_for_each_positive_output_that_traces_back(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $posted = $this->chip($this->draftOrder('2000.000', $lot));

        foreach ($posted->outputs as $output) {
            $this->assertNotNull($output->lot_id);

            $outputLot = InventoryLot::findOrFail($output->lot_id);
            $this->assertSame($output->product_id, $outputLot->product_id);
            $this->assertSame(ProductionOrder::class, $outputLot->source_type);
            $this->assertSame($posted->id, (int) $outputLot->source_id);
            // The consumed husk lot is named, completing the chain.
            $this->assertStringContainsString($lot->lot_number, (string) $outputLot->notes);
        }

        $this->assertSame($lot->id, $posted->inputs->first()->lot_id);
        $this->assertSame('4000.000', $this->stock()->quantityForLot($lot->id));
    }

    #[Test]
    public function the_ledger_records_both_a_consumption_and_an_output_transaction(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $posted = $this->chip($this->draftOrder('2000.000', $lot));

        $this->assertSame(InventoryTransactionType::ProductionConsumption, $posted->consumptionTransaction->transaction_type);
        $this->assertSame(InventoryDirection::Out, $posted->consumptionTransaction->lines->first()->direction);

        $this->assertSame(InventoryTransactionType::ProductionOutput, $posted->outputTransaction->transaction_type);
        $this->assertCount(3, $posted->outputTransaction->lines);
    }

    #[Test]
    public function the_input_weighted_average_is_captured_from_the_ledger(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '19.500000', $lot);

        $posted = $this->chip($this->draftOrder('2000.000', $lot));
        $input = $posted->inputs->first();

        $this->assertSame('19.500000', $input->unit_cost);
        $this->assertSame('39000.000000', $input->total_cost);
    }

    // ------------------------------------------------------------- atomicity

    #[Test]
    public function a_failed_posting_creates_no_lots_and_no_partial_ledger(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        // Deactivating an output makes the OUTPUT half fail, after the consumption half has
        // already been written — the whole thing must still roll back.
        $this->c10->update(['is_active' => false]);

        $transactionsBefore = InventoryTransaction::count();
        $lotsBefore = InventoryLot::count();

        $order = $this->draftOrder('2000.000', $lot);

        try {
            $this->chip($order);
            $this->fail('Expected the posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($transactionsBefore, InventoryTransaction::count());
        $this->assertSame($lotsBefore, InventoryLot::count());
        $this->assertSame('6000.000', $this->stock()->quantityForProduct($this->husk->id));
        $this->assertSame(ProductionStatus::Draft, $order->fresh()->status);
        $this->assertNull($order->fresh()->inputs()->first()->total_cost);
        $this->assertNull($order->fresh()->outputs()->first()->allocated_cost);
    }

    #[Test]
    public function a_production_order_cannot_be_posted_twice(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $posted = $this->chip($this->draftOrder('2000.000', $lot));

        try {
            $this->chip($posted);
            $this->fail('Expected double posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('4000.000', $this->stock()->quantityForProduct($this->husk->id));
    }

    #[Test]
    public function a_posted_production_order_is_immutable(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $posted = $this->chip($this->draftOrder('2000.000', $lot));

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
    }

    #[Test]
    public function a_dehusking_order_cannot_be_posted_by_the_chipping_action(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $this->expectException(ValidationException::class);

        $this->chip($this->draftOrder('100.000', $lot, [], [
            'production_type' => ProductionType::Dehusking,
        ]));
    }

    // ------------------------------------------------------------- reversal

    #[Test]
    public function reversing_restores_the_husk_and_removes_every_chip_grade(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '19.500000', $lot);

        $posted = $this->chip($this->draftOrder('2000.000', $lot));
        $reversed = app(ReverseProduction::class)->handle($posted);

        $this->assertSame(ProductionStatus::Reversed, $reversed->status);

        $this->assertSame('6000.000', $this->stock()->quantityForProduct($this->husk->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->c7->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->c9->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->c10->id));

        $this->assertSame('117000.000000', $this->stock()->valueForProduct($this->husk->id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($this->c7->id));
    }

    #[Test]
    public function a_reversal_uses_the_original_production_costs(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $posted = $this->chip($this->draftOrder('2000.000', $lot, [
            'HUSK-7C' => '150.000', 'HUSK-9C' => '200.000', 'HUSK-10C' => '280.000',
        ]));

        // Move the husk average well away from the original 20.
        $second = $this->lotFor($this->husk);
        $this->receive('4000.000', '80.000000', $second);

        $reversed = app(ReverseProduction::class)->handle($posted);

        $consumptionReversal = InventoryTransaction::findOrFail($reversed->reversal_inventory_transaction_id);
        $outputReversal = InventoryTransaction::findOrFail($reversed->output_reversal_transaction_id);

        // Husk returns at the 20 it left at, not the drifted average.
        $this->assertSame('20.000000', $consumptionReversal->lines->first()->unit_cost);

        $costs = $outputReversal->lines->pluck('unit_cost')->sort()->values()->all();
        $this->assertSame(['57.142857', '60.000000', '80.000000'], $costs);
    }

    #[Test]
    public function a_production_order_cannot_be_reversed_twice(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $posted = $this->chip($this->draftOrder('2000.000', $lot));
        app(ReverseProduction::class)->handle($posted);

        $this->expectException(ValidationException::class);

        app(ReverseProduction::class)->handle($posted->fresh());
    }

    /**
     * Selling any one grade must block the whole reversal — there is no partial reversal.
     */
    #[Test]
    public function reversal_is_rejected_when_any_chip_grade_has_been_consumed(): void
    {
        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            $product = match ($code) {
                'HUSK-7C' => $this->c7,
                'HUSK-9C' => $this->c9,
                default => $this->c10,
            };

            $lot = $this->lotFor($this->husk);
            $this->receive('6000.000', '20.000000', $lot);

            $posted = $this->chip($this->draftOrder('2000.000', $lot, [
                'HUSK-7C' => '150.000', 'HUSK-9C' => '200.000', 'HUSK-10C' => '280.000',
            ]));

            // Sell most of this grade.
            app(PostInventoryTransaction::class)->handle(InventoryTransactionType::SalesIssue, [
                new PendingInventoryLine(
                    productId: $product->id,
                    warehouseId: $this->main->id,
                    direction: InventoryDirection::Out,
                    quantity: '100.000',
                    lotId: $posted->outputs->firstWhere('product_id', $product->id)->lot_id,
                ),
            ]);

            try {
                app(ReverseProduction::class)->handle($posted);
                $this->fail("Expected the reversal to be rejected after {$code} was consumed.");
            } catch (ValidationException) {
                // expected
            }

            // Nothing moved — no partial reversal.
            $fresh = $posted->fresh();
            $this->assertSame(ProductionStatus::Posted, $fresh->status, "after {$code}");
            $this->assertNull($fresh->reversal_inventory_transaction_id);
            $this->assertSame('4000.000', $this->stock()->quantityForLot($lot->id));
        }
    }

    // ------------------------------------------------------------- cancellation & scope

    #[Test]
    public function a_draft_can_be_cancelled_and_creates_no_stock(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $order = $this->draftOrder('2000.000', $lot);
        $cancelled = app(CancelProduction::class)->handle($order);

        $this->assertSame(ProductionStatus::Cancelled, $cancelled->status);
        $this->assertSame('6000.000', $this->stock()->quantityForProduct($this->husk->id));
    }

    #[Test]
    public function chipping_creates_no_finance_or_general_ledger_records(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $this->chip($this->draftOrder('2000.000', $lot));

        foreach ([
            'accounts', 'journals', 'journal_lines', 'general_ledger',
            'production_costs', 'labour_costs', 'overheads', 'packaging_costs',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Chipping must not create a [{$table}] table.");
        }

        foreach (['labour_cost', 'electricity_cost', 'machine_cost', 'overhead_cost', 'packaging_cost'] as $column) {
            $this->assertFalse(Schema::hasColumn('production_orders', $column));
            $this->assertFalse(Schema::hasColumn('production_outputs', $column));
        }
    }

    #[Test]
    public function no_dust_product_is_created(): void
    {
        $lot = $this->lotFor($this->husk);
        $this->receive('6000.000', '20.000000', $lot);

        $this->chip($this->draftOrder('2000.000', $lot));

        // Waste is not inventory, so no scrap product may appear.
        $this->assertSame(0, Product::where('code', 'like', '%DUST%')->count());
        $this->assertSame(0, Product::where('code', 'like', '%WASTE%')->count());
    }

    #[Test]
    public function the_products_table_still_has_no_stock_column(): void
    {
        foreach (['current_stock', 'stock', 'stock_quantity', 'quantity', 'on_hand'] as $column) {
            $this->assertFalse(Schema::hasColumn('products', $column));
        }
    }
}
