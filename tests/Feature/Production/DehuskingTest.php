<?php

namespace Tests\Feature\Production;

use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Production\CancelProduction;
use App\Actions\Production\GenerateProductionReference;
use App\Actions\Production\PostDehuskingProduction;
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

class DehuskingTest extends TestCase
{
    use RefreshDatabase;

    private Product $coconut;

    private Product $dehusked;

    private Product $husk;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);

        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary_uom_id' => $nos->id]);
        $this->dehusked = Product::factory()->create(['code' => 'DEHUSKED-COCONUT', 'name' => 'Dehusked Coconut', 'primary_uom_id' => $nos->id]);
        $this->husk = Product::factory()->create(['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary_uom_id' => $nos->id]);

        // The recipe seeder configures every production type at once, so the husk chipping
        // products must exist too.
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);
        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            Product::factory()->create(['code' => $code, 'primary_uom_id' => $kg->id]);
        }

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

    /** Put coconut on hand at a known cost, in a known lot. */
    private function receive(string $quantity, string $unitCost, InventoryLot $lot, ?Product $product = null): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: ($product ?? $this->coconut)->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lot->id,
                unitCost: $unitCost,
            ),
        ]);
    }

    private function draftOrder(string $quantity, ?InventoryLot $lot = null, array $attributes = [], ?Product $product = null): ProductionOrder
    {
        $order = ProductionOrder::factory()->create(array_merge([
            'warehouse_id' => $this->main->id,
        ], $attributes));

        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => ($product ?? $this->coconut)->id,
            'lot_id' => $lot?->id,
            'quantity' => $quantity,
        ]);

        return $order->refresh();
    }

    private function runProduction(ProductionOrder $order): ProductionOrder
    {
        return app(PostDehuskingProduction::class)->handle($order);
    }

    // ------------------------------------------------------------- drafts

    #[Test]
    public function a_draft_dehusking_order_can_be_created(): void
    {
        $lot = $this->lotFor($this->coconut);
        $order = $this->draftOrder('1000.000', $lot);

        $this->assertSame(ProductionStatus::Draft, $order->status);
        $this->assertSame(ProductionType::Dehusking, $order->production_type);
        $this->assertSame('1000.000', $order->inputQuantity());
    }

    #[Test]
    public function a_draft_creates_no_inventory_movement(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('10000.000', '102.500000', $lot);

        $before = InventoryTransaction::count();
        $this->draftOrder('1000.000', $lot);

        $this->assertSame($before, InventoryTransaction::count());
        $this->assertSame('10000.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->dehusked->id));
    }

    // ------------------------------------------------------------- the 1:1:1 rule

    #[Test]
    public function processing_1000_coconut_yields_1000_dehusked_and_1000_husk(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('10000.000', '102.500000', $lot);

        $posted = $this->runProduction($this->draftOrder('1000.000', $lot));

        // Exactly the scenario from the specification.
        $this->assertSame('9000.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->dehusked->id));
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->husk->id));
        $this->assertSame(ProductionStatus::Posted, $posted->status);
    }

    #[Test]
    public function output_quantities_are_derived_from_the_input_and_cannot_be_overridden(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('5000.000', '100.000000', $lot);

        // No output quantity is supplied anywhere; the recipe ratio decides it.
        $posted = $this->runProduction($this->draftOrder('1234.000', $lot));

        $outputs = $posted->outputs->keyBy('product_id');

        $this->assertSame('1234.000', $outputs[$this->dehusked->id]->quantity);
        $this->assertSame('1234.000', $outputs[$this->husk->id]->quantity);
    }

    #[Test]
    public function the_one_to_one_ratio_comes_from_the_recipe_not_from_code(): void
    {
        $recipe = ProductionRecipe::forType(ProductionType::Dehusking);

        $this->assertNotNull($recipe);
        $this->assertSame($this->coconut->id, $recipe->input_product_id);

        foreach ($recipe->outputs as $output) {
            $this->assertSame('1.000000', $output->output_ratio);
        }

        // Changing the ratio changes the outcome — proving it is configuration.
        $recipe->outputs()->where('product_id', $this->husk->id)->update(['output_ratio' => '2.000000']);

        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $posted = $this->runProduction($this->draftOrder('100.000', $lot));
        $outputs = $posted->outputs->keyBy('product_id');

        $this->assertSame('100.000', $outputs[$this->dehusked->id]->quantity);
        $this->assertSame('200.000', $outputs[$this->husk->id]->quantity);
    }

    // ------------------------------------------------------------- costing

    #[Test]
    public function the_consumed_value_is_taken_from_the_ledger_weighted_average(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('10000.000', '102.500000', $lot);

        $posted = $this->runProduction($this->draftOrder('1000.000', $lot));
        $input = $posted->inputs->first();

        $this->assertSame('102.500000', $input->unit_cost);
        $this->assertSame('102500.000000', $input->total_cost);
    }

    #[Test]
    public function the_configured_allocation_is_applied_to_the_outputs(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('10000.000', '102.500000', $lot);

        $posted = $this->runProduction($this->draftOrder('1000.000', $lot));
        $outputs = $posted->outputs->keyBy('product_id');

        // The worked example: 102,500 split 85 / 15.
        $this->assertSame('85.000000', $outputs[$this->dehusked->id]->allocation_percent);
        $this->assertSame('87125.000000', $outputs[$this->dehusked->id]->allocated_cost);
        $this->assertSame('87.125000', $outputs[$this->dehusked->id]->unit_cost);

        $this->assertSame('15.000000', $outputs[$this->husk->id]->allocation_percent);
        $this->assertSame('15375.000000', $outputs[$this->husk->id]->allocated_cost);
        $this->assertSame('15.375000', $outputs[$this->husk->id]->unit_cost);
    }

    #[Test]
    public function production_neither_creates_nor_destroys_inventory_value(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('10000.000', '102.500000', $lot);

        $valueBefore = $this->stock()->valueForProduct($this->coconut->id);
        $this->assertSame('1025000.000000', $valueBefore);

        $posted = $this->runProduction($this->draftOrder('1000.000', $lot));

        // Allocated costs sum EXACTLY to the consumed value.
        $this->assertSame($posted->inputValue(), $posted->outputValue());
        $this->assertSame('102500.000000', $posted->outputValue());

        // And across the whole ledger: quantity doubled across product types, money did not.
        $totalAfter = bcadd(
            bcadd(
                $this->stock()->valueForProduct($this->coconut->id),
                $this->stock()->valueForProduct($this->dehusked->id),
                6,
            ),
            $this->stock()->valueForProduct($this->husk->id),
            6,
        );

        $this->assertSame($valueBefore, $totalAfter);
    }

    #[Test]
    public function allocated_costs_sum_exactly_even_when_the_split_does_not_divide_evenly(): void
    {
        // A consumed value that 85/15 cannot split cleanly.
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '33.333333', $lot);

        $posted = $this->runProduction($this->draftOrder('7.000', $lot));

        $consumed = $posted->inputs->first()->total_cost;
        $allocated = $posted->outputs->reduce(
            fn (string $carry, $output): string => bcadd($carry, $output->allocated_cost, 6),
            '0.000000',
        );

        // The final output absorbs the remainder, so this is exact whatever the numbers.
        $this->assertSame($consumed, $allocated);
    }

    #[Test]
    public function a_recipe_that_does_not_allocate_one_hundred_percent_is_rejected(): void
    {
        $recipe = ProductionRecipe::forType(ProductionType::Dehusking);
        $recipe->outputs()->where('product_id', $this->husk->id)->update(['allocation_percent' => '10.000000']);

        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        try {
            $this->runProduction($this->draftOrder('100.000', $lot));
            $this->fail('Expected a 95% recipe to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        // Nothing was consumed or produced.
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame(1, InventoryTransaction::count());
    }

    // ------------------------------------------------------------- validation

    #[Test]
    public function an_input_lot_is_required(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $this->expectException(ValidationException::class);

        // No lot: output traceability would be broken.
        $this->runProduction($this->draftOrder('100.000', null));
    }

    #[Test]
    public function a_lot_belonging_to_another_product_is_rejected(): void
    {
        $coconutLot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $coconutLot);

        $huskLot = $this->lotFor($this->husk);

        $this->expectException(ValidationException::class);

        $this->runProduction($this->draftOrder('100.000', $huskLot));
    }

    #[Test]
    public function an_input_product_other_than_coconut_is_rejected(): void
    {
        $huskLot = $this->lotFor($this->husk);
        $this->receive('1000.000', '10.000000', $huskLot, $this->husk);

        $this->expectException(ValidationException::class);

        $this->runProduction($this->draftOrder('100.000', $huskLot, product: $this->husk));
    }

    #[Test]
    public function insufficient_coconut_stock_is_rejected(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('100.000', '100.000000', $lot);

        try {
            $this->runProduction($this->draftOrder('101.000', $lot));
            $this->fail('Expected insufficient stock to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function a_zero_quantity_is_rejected(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $this->expectException(ValidationException::class);

        $this->runProduction($this->draftOrder('0.000', $lot));
    }

    #[Test]
    public function a_negative_quantity_is_rejected(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $this->expectException(ValidationException::class);

        $this->runProduction($this->draftOrder('-50.000', $lot));
    }

    #[Test]
    public function an_inactive_warehouse_is_rejected(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $inactive = Warehouse::factory()->inactive()->create(['code' => 'WH-OFF']);

        $this->expectException(ValidationException::class);

        $this->runProduction($this->draftOrder('100.000', $lot, ['warehouse_id' => $inactive->id]));
    }

    #[Test]
    public function an_unimplemented_production_type_cannot_be_posted(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $this->expectException(ValidationException::class);

        $this->runProduction($this->draftOrder('100.000', $lot, ['production_type' => ProductionType::Copra]));
    }

    // ------------------------------------------------------------- lots and traceability

    #[Test]
    public function posting_creates_a_new_lot_for_each_output(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $lotsBefore = InventoryLot::count();
        $posted = $this->runProduction($this->draftOrder('100.000', $lot));

        $this->assertSame($lotsBefore + 2, InventoryLot::count());

        foreach ($posted->outputs as $output) {
            $this->assertNotNull($output->lot_id);

            $outputLot = InventoryLot::findOrFail($output->lot_id);
            $this->assertSame($output->product_id, $outputLot->product_id);
            $this->assertMatchesRegularExpression('/^LOT-\d{8}-\d{6}$/', $outputLot->lot_number);
        }
    }

    #[Test]
    public function output_lots_trace_back_to_the_production_order(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $posted = $this->runProduction($this->draftOrder('100.000', $lot));

        foreach ($posted->outputs as $output) {
            $outputLot = InventoryLot::findOrFail($output->lot_id);

            $this->assertSame(ProductionOrder::class, $outputLot->source_type);
            $this->assertSame($posted->id, (int) $outputLot->source_id);
            // The consumed lot is named on the output lot, completing the chain.
            $this->assertStringContainsString($lot->lot_number, (string) $outputLot->notes);
        }
    }

    #[Test]
    public function the_consumed_lot_is_preserved_on_the_production_order(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $posted = $this->runProduction($this->draftOrder('100.000', $lot));

        $this->assertSame($lot->id, $posted->inputs->first()->lot_id);
        // And the lot really lost that stock.
        $this->assertSame('900.000', $this->stock()->quantityForLot($lot->id));
    }

    #[Test]
    public function the_ledger_records_both_a_consumption_and_an_output_transaction(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $posted = $this->runProduction($this->draftOrder('100.000', $lot));

        $consumption = $posted->consumptionTransaction;
        $output = $posted->outputTransaction;

        $this->assertSame(InventoryTransactionType::ProductionConsumption, $consumption->transaction_type);
        $this->assertSame(InventoryDirection::Out, $consumption->lines->first()->direction);
        $this->assertSame(ProductionOrder::class, $consumption->source_type);

        $this->assertSame(InventoryTransactionType::ProductionOutput, $output->transaction_type);
        $this->assertCount(2, $output->lines);
        foreach ($output->lines as $line) {
            $this->assertSame(InventoryDirection::In, $line->direction);
        }
    }

    // ------------------------------------------------------------- atomicity

    #[Test]
    public function a_failed_posting_creates_no_lots_and_no_partial_ledger(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('100.000', '100.000000', $lot);

        // Deactivating an output product makes the OUTPUT half fail, after the consumption
        // half has already been written — the whole thing must still roll back.
        $this->husk->update(['is_active' => false]);

        $transactionsBefore = InventoryTransaction::count();
        $lotsBefore = InventoryLot::count();

        $order = $this->draftOrder('50.000', $lot);

        try {
            $this->runProduction($order);
            $this->fail('Expected the posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($transactionsBefore, InventoryTransaction::count());
        $this->assertSame($lotsBefore, InventoryLot::count());
        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame(ProductionStatus::Draft, $order->fresh()->status);
        $this->assertSame(0, $order->fresh()->outputs()->count());
        $this->assertNull($order->fresh()->inputs()->first()->total_cost);
    }

    #[Test]
    public function a_production_order_cannot_be_posted_twice(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $posted = $this->runProduction($this->draftOrder('100.000', $lot));

        try {
            $this->runProduction($posted);
            $this->fail('Expected double posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('900.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->dehusked->id));
    }

    #[Test]
    public function a_posted_production_order_is_immutable(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $posted = $this->runProduction($this->draftOrder('100.000', $lot));

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

        $this->assertDatabaseHas('production_orders', ['id' => $posted->id, 'notes' => null]);
    }

    // ------------------------------------------------------------- reversal

    #[Test]
    public function reversing_production_restores_the_coconut_and_removes_the_outputs(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('10000.000', '102.500000', $lot);

        $posted = $this->runProduction($this->draftOrder('1000.000', $lot));
        $reversed = app(ReverseProduction::class)->handle($posted);

        $this->assertSame(ProductionStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversal_inventory_transaction_id);
        $this->assertNotNull($reversed->output_reversal_transaction_id);

        $this->assertSame('10000.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->dehusked->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->husk->id));

        // Value is back where it started, undistorted.
        $this->assertSame('1025000.000000', $this->stock()->valueForProduct($this->coconut->id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($this->dehusked->id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($this->husk->id));
    }

    #[Test]
    public function a_reversal_uses_the_original_production_costs(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('10000.000', '100.000000', $lot);

        $posted = $this->runProduction($this->draftOrder('1000.000', $lot));

        // Move the coconut average well away from the original 100.
        $secondLot = $this->lotFor($this->coconut);
        $this->receive('9000.000', '300.000000', $secondLot);

        $reversed = app(ReverseProduction::class)->handle($posted);

        $consumptionReversal = InventoryTransaction::findOrFail($reversed->reversal_inventory_transaction_id);
        $outputReversal = InventoryTransaction::findOrFail($reversed->output_reversal_transaction_id);

        // Coconut returns at the 100 it left at, not the drifted average.
        $this->assertSame('100.000000', $consumptionReversal->lines->first()->unit_cost);

        // Outputs leave at the costs they were produced at.
        $costs = $outputReversal->lines->pluck('unit_cost')->sort()->values()->all();
        $this->assertSame(['15.000000', '85.000000'], $costs);
    }

    #[Test]
    public function a_production_order_cannot_be_reversed_twice(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $posted = $this->runProduction($this->draftOrder('100.000', $lot));
        app(ReverseProduction::class)->handle($posted);

        $this->expectException(ValidationException::class);

        app(ReverseProduction::class)->handle($posted->fresh());
    }

    #[Test]
    public function reversal_is_rejected_when_the_dehusked_output_has_been_consumed(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('10000.000', '102.500000', $lot);

        $posted = $this->runProduction($this->draftOrder('1000.000', $lot));

        // Sell half the dehusked coconut — the production can no longer be undone.
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::SalesIssue, [
            new PendingInventoryLine(
                productId: $this->dehusked->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::Out,
                quantity: '500.000',
                lotId: $posted->outputs->firstWhere('product_id', $this->dehusked->id)->lot_id,
            ),
        ]);

        try {
            app(ReverseProduction::class)->handle($posted);
            $this->fail('Expected the reversal to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        // Nothing moved: the coconut was not restored, the husk was not removed.
        $fresh = $posted->fresh();
        $this->assertSame(ProductionStatus::Posted, $fresh->status);
        $this->assertNull($fresh->reversal_inventory_transaction_id);
        $this->assertSame('9000.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('500.000', $this->stock()->quantityForProduct($this->dehusked->id));
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->husk->id));
    }

    #[Test]
    public function reversal_is_rejected_when_the_husk_output_has_been_consumed(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('10000.000', '102.500000', $lot);

        $posted = $this->runProduction($this->draftOrder('1000.000', $lot));

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::SalesIssue, [
            new PendingInventoryLine(
                productId: $this->husk->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::Out,
                quantity: '600.000',
                lotId: $posted->outputs->firstWhere('product_id', $this->husk->id)->lot_id,
            ),
        ]);

        try {
            app(ReverseProduction::class)->handle($posted);
            $this->fail('Expected the reversal to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(ProductionStatus::Posted, $posted->fresh()->status);
        $this->assertSame('9000.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->dehusked->id));
    }

    // ------------------------------------------------------------- cancellation

    #[Test]
    public function a_draft_production_order_can_be_cancelled(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $order = $this->draftOrder('100.000', $lot);
        $cancelled = app(CancelProduction::class)->handle($order);

        $this->assertSame(ProductionStatus::Cancelled, $cancelled->status);
        $this->assertFalse($cancelled->status->reachedInventory());
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertDatabaseHas('production_orders', ['id' => $order->id]);
    }

    #[Test]
    public function a_posted_production_order_cannot_be_cancelled(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $posted = $this->runProduction($this->draftOrder('100.000', $lot));

        $this->expectException(ValidationException::class);

        app(CancelProduction::class)->handle($posted);
    }

    // ------------------------------------------------------------- scope guards

    #[Test]
    public function production_creates_no_finance_or_general_ledger_records(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('1000.000', '100.000000', $lot);

        $this->runProduction($this->draftOrder('100.000', $lot));

        foreach ([
            'accounts', 'journals', 'journal_lines', 'general_ledger',
            'production_costs', 'labour_costs', 'overheads',
        ] as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "Production must not create a [{$table}] table — costing and finance are later phases."
            );
        }

        // No labour, electricity, machine or overhead columns on the production tables.
        foreach (['labour_cost', 'electricity_cost', 'machine_cost', 'overhead_cost', 'fuel_cost'] as $column) {
            $this->assertFalse(Schema::hasColumn('production_orders', $column));
            $this->assertFalse(Schema::hasColumn('production_outputs', $column));
        }
    }

    #[Test]
    public function the_products_table_still_has_no_stock_column(): void
    {
        foreach (['current_stock', 'stock', 'stock_quantity', 'quantity', 'on_hand'] as $column) {
            $this->assertFalse(Schema::hasColumn('products', $column));
        }
    }

    #[Test]
    public function production_numbers_follow_the_documented_format(): void
    {
        $reference = app(GenerateProductionReference::class);

        $this->assertMatchesRegularExpression('/^PRD-\d{8}-\d{6}$/', $reference->forProductionOrder());
    }
}
