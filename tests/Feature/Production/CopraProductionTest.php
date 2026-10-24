<?php

namespace Tests\Feature\Production;

use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Production\CancelProduction;
use App\Actions\Production\PostCopraProduction;
use App\Actions\Production\ReverseProduction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\ProductionRecipe;
use App\Models\UnitOfMeasure;
use App\Models\User;
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
 * Copra production.
 *
 * The behaviour these tests pin down is the mixture: the shell COUNT is derived one per
 * coconut, while the copra WEIGHT and the shell WEIGHT are both measured by the operator.
 * No fixed NOS-to-KG conversion exists anywhere, and value is conserved exactly.
 */
class CopraProductionTest extends TestCase
{
    use RefreshDatabase;

    private UnitOfMeasure $nos;

    private UnitOfMeasure $kg;

    private Product $dehusked;

    private Product $copra;

    private Product $shell;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        // Every recipe is seeded together, so the dehusking and chipping products must
        // exist as well.
        Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => $this->nos->id]);
        Product::factory()->create(['code' => 'RAW-HUSK', 'primary_uom_id' => $this->nos->id]);
        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            Product::factory()->create(['code' => $code, 'primary_uom_id' => $this->kg->id]);
        }

        $this->dehusked = Product::factory()->create([
            'code' => 'DEHUSKED-COCONUT',
            'name' => 'Dehusked Coconut',
            'primary_uom_id' => $this->nos->id,
        ]);
        $this->copra = Product::factory()->create([
            'code' => 'COPRA',
            'name' => 'Copra',
            'primary_uom_id' => $this->kg->id,
        ]);
        $this->shell = Product::factory()->create([
            'code' => 'RAW-SHELL',
            'name' => 'Raw Coconut Shell',
            'primary_uom_id' => $this->nos->id,
            'secondary_uom_id' => $this->kg->id,
        ]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);

        $this->seedProductionRecipes();
        // The allocation mechanics need a recipe that splits; the real one gives the shell nothing.
        $this->splitCopraValue('75.000000', '25.000000');

    }

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    private function lotFor(Product $product): InventoryLot
    {
        return app(CreateInventoryLot::class)->handle($product);
    }

    /** Put dehusked coconut on hand at a known cost, in a known lot. */
    private function receive(string $quantity, string $unitCost, InventoryLot $lot, ?Product $product = null): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: ($product ?? $this->dehusked)->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lot->id,
                unitCost: $unitCost,
            ),
        ]);
    }

    /**
     * A draft copra order.
     *
     * `$shellQuantity` exists only so a test can try to override the derived shell count;
     * ordinary callers leave it null and let the draft carry the calculated figure, exactly
     * as the controller writes it.
     */
    private function draftOrder(
        string $inputQuantity,
        ?InventoryLot $lot = null,
        string $copraQuantity = '190.500',
        ?string $shellSecondary = '420.750',
        ?string $shellQuantity = null,
        array $attributes = [],
        ?Product $inputProduct = null,
    ): ProductionOrder {
        $order = ProductionOrder::factory()->copra()->create(array_merge([
            'warehouse_id' => $this->main->id,
        ], $attributes));

        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => ($inputProduct ?? $this->dehusked)->id,
            'lot_id' => $lot?->id,
            'quantity' => $inputQuantity,
        ]);

        ProductionOutput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->copra->id,
            'quantity' => $copraQuantity,
            'secondary_quantity' => null,
            'allocated_cost' => null,
            'unit_cost' => null,
        ]);

        ProductionOutput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->shell->id,
            'quantity' => $shellQuantity ?? $inputQuantity,
            'secondary_quantity' => $shellSecondary,
            'allocated_cost' => null,
            'unit_cost' => null,
        ]);

        return $order->refresh();
    }

    private function dry(ProductionOrder $order): ProductionOrder
    {
        return app(PostCopraProduction::class)->handle($order);
    }

    private function outputFor(ProductionOrder $order, Product $product): ProductionOutput
    {
        return $order->outputs->firstWhere('product_id', $product->id);
    }

    // ------------------------------------------------------------- drafts

    #[Test]
    public function a_draft_copra_production_order_can_be_created(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $order = $this->draftOrder('1000.000', $lot);

        $this->assertSame(ProductionStatus::Draft, $order->status);
        $this->assertSame(ProductionType::Copra, $order->production_type);
        $this->assertSame('1000.000', $order->inputQuantity());
        $this->assertCount(2, $order->outputs);
    }

    #[Test]
    public function a_draft_creates_no_inventory_movement(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $before = InventoryTransaction::count();
        $this->draftOrder('1000.000', $lot);

        $this->assertSame($before, InventoryTransaction::count());
        $this->assertSame('5000.000', $this->stock()->quantityForProduct($this->dehusked->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->copra->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->shell->id));
    }

    #[Test]
    public function copra_production_is_implemented_and_reuses_the_one_production_engine(): void
    {
        $this->assertTrue(ProductionType::Copra->isImplemented());

        // Not every output is derived, so the type-level answer is false — the per-output
        // recipe flag is the authority.
        $this->assertFalse(ProductionType::Copra->hasFixedOutputRatio());
        $this->assertTrue(ProductionType::Copra->hasOperatorEnteredOutputs());

        // No second engine: the same tables and the same shared actions.
        $this->assertTrue(Schema::hasTable('production_orders'));
        $this->assertTrue(Schema::hasTable('production_inputs'));
        $this->assertTrue(Schema::hasTable('production_outputs'));
        $this->assertFalse(Schema::hasTable('copra_production_orders'));
    }

    // ------------------------------------------------------------- the recipe

    #[Test]
    public function the_recipe_derives_the_shell_count_and_measures_both_weights(): void
    {
        $recipe = ProductionRecipe::forType(ProductionType::Copra);

        $this->assertNotNull($recipe);
        $this->assertSame($this->dehusked->id, $recipe->input_product_id);

        $outputs = $recipe->outputs->keyBy('product_id');

        // Copra: no ratio at all, so nothing can derive a weight from the coconut count.
        $this->assertNull($outputs[$this->copra->id]->output_ratio);
        $this->assertFalse($outputs[$this->copra->id]->derivesQuantity());
        $this->assertFalse($outputs[$this->copra->id]->requires_secondary_quantity);

        // Shell: one per coconut; its weight is optional since recipe version 2 (no scale).
        $this->assertSame('1.000000', $outputs[$this->shell->id]->output_ratio);
        $this->assertTrue($outputs[$this->shell->id]->derivesQuantity());
        $this->assertFalse($outputs[$this->shell->id]->requires_secondary_quantity);
    }

    #[Test]
    public function the_seeded_recipe_gives_the_copra_the_whole_value_and_the_shell_none(): void
    {
        // Undo setUp's split: this test examines the recipe exactly as seeded.
        $this->seed(ProductionRecipeSeeder::class);

        $recipe = ProductionRecipe::forType(ProductionType::Copra);
        $percentages = $recipe->outputs->pluck('allocation_percent', 'product_id');

        $this->assertSame(3, $recipe->version);
        $this->assertSame('100.000000', $percentages[$this->copra->id]);
        $this->assertSame('0.000000', $percentages[$this->shell->id]);
        $this->assertSame('100.000000', $recipe->allocationTotal());
    }

    #[Test]
    public function the_migration_moves_an_existing_split_recipe_to_a_new_version_with_free_shell(): void
    {
        // setUp left the active recipe splitting 75 / 25, as an existing database's would.
        $old = ProductionRecipe::forType(ProductionType::Copra);
        $migration = require database_path('migrations/2026_10_24_160430_give_copra_the_whole_coconut_value.php');

        $migration->up();
        $migration->up(); // A second run changes nothing.

        $current = ProductionRecipe::forType(ProductionType::Copra);
        $this->assertSame($old->version + 1, $current->version);
        $this->assertSame(2, ProductionRecipe::query()->where('production_type', ProductionType::Copra)->where('input_product_id', $old->input_product_id)->count());

        $percentages = $current->outputs->pluck('allocation_percent', 'product_id');
        $this->assertSame('100.000000', $percentages[$this->copra->id]);
        $this->assertSame('0.000000', $percentages[$this->shell->id]);
        $this->assertSame('1.000000', $current->outputs->firstWhere('product_id', $this->shell->id)->output_ratio);

        // The old version is kept, untouched, for the runs that used it.
        $old->refresh()->load('outputs');
        $this->assertFalse($old->is_active);
        $this->assertSame('75.000000', $old->outputs->firstWhere('product_id', $this->copra->id)->allocation_percent);
    }

    #[Test]
    public function no_fixed_coconut_to_copra_or_shell_kilogram_conversion_exists(): void
    {
        $first = $this->lotFor($this->dehusked);
        $second = $this->lotFor($this->dehusked);
        $this->receive('1000.000', '80.000000', $first);
        $this->receive('1000.000', '80.000000', $second);

        // The same coconut count, deliberately yielding different weights.
        $this->dry($this->draftOrder('1000.000', $first, '190.500', '420.750'));
        $this->dry($this->draftOrder('1000.000', $second, '164.250', '512.400'));

        // Weights differ; only the counts match.
        $this->assertSame('354.750', $this->stock()->quantityForProduct($this->copra->id));
        $this->assertSame('2000.000', $this->stock()->quantityForProduct($this->shell->id));
        $this->assertSame('933.150', $this->stock()->secondaryQuantityForProduct($this->shell->id));
    }

    // ------------------------------------------------------------- the worked example

    #[Test]
    public function processing_one_thousand_dehusked_coconut_moves_stock_by_the_measured_outputs(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot, '190.500', '420.750'));

        // Coconut out.
        $this->assertSame('4000.000', $this->stock()->quantityForProduct($this->dehusked->id));

        // Copra in, at the measured weight.
        $this->assertSame('190.500', $this->stock()->quantityForProduct($this->copra->id));

        // Shell in: one per coconut, weighed separately.
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->shell->id));
        $this->assertSame('420.750', $this->stock()->secondaryQuantityForProduct($this->shell->id));

        $this->assertSame(ProductionStatus::Posted, $posted->status);
        $this->assertNotNull($posted->posted_at);
    }

    #[Test]
    public function posting_records_who_posted_it_and_when(): void
    {
        $user = User::factory()->admin()->create();
        $this->actingAs($user);

        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot));

        $this->assertSame($user->id, $posted->posted_by);
        $this->assertNotNull($posted->posted_at);
    }

    #[Test]
    public function the_shell_count_equals_the_coconut_count_processed(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1234.000', $lot, '210.000', '500.000'));

        $this->assertSame('1234.000', $this->outputFor($posted, $this->shell)->quantity);
        $this->assertSame('1234.000', $this->stock()->quantityForProduct($this->shell->id));
    }

    #[Test]
    public function an_operator_supplied_shell_count_is_recalculated_and_cannot_override_the_rule(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        // A draft doctored to claim far more shells than coconuts processed.
        $posted = $this->dry($this->draftOrder('1000.000', $lot, '190.500', '420.750', shellQuantity: '9999.000'));

        $this->assertSame('1000.000', $this->outputFor($posted, $this->shell)->quantity);
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->shell->id));
    }

    #[Test]
    public function the_measured_shell_weight_reaches_the_ledger_as_the_secondary_quantity(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot, '190.500', '420.750'));

        $shellLine = $posted->outputTransaction->lines->firstWhere('product_id', $this->shell->id);
        $copraLine = $posted->outputTransaction->lines->firstWhere('product_id', $this->copra->id);

        $this->assertSame('1000.000', $shellLine->quantity);
        $this->assertSame('420.750', $shellLine->secondary_quantity);

        // Copra has no secondary unit, so it records no weight beyond its primary one.
        $this->assertNull($copraLine->secondary_quantity);
        $this->assertSame('190.500', $copraLine->quantity);
    }

    // ------------------------------------------------------------- costing

    #[Test]
    public function the_costing_example_splits_the_consumed_value_seventy_five_twenty_five(): void
    {
        // The specification's example: Rs.100,000 consumed, 200 KG copra, 1,000 shells.
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '100.000000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot, '200.000', '420.000'));

        $this->assertSame('100.000000', $posted->inputs->first()->unit_cost);
        $this->assertSame('100000.000000', $posted->inputs->first()->total_cost);

        $copra = $this->outputFor($posted, $this->copra);
        $shell = $this->outputFor($posted, $this->shell);

        $this->assertSame('75.000000', $copra->allocation_percent);
        $this->assertSame('75000.000000', $copra->allocated_cost);
        $this->assertSame('375.000000', $copra->unit_cost);

        $this->assertSame('25.000000', $shell->allocation_percent);
        $this->assertSame('25000.000000', $shell->allocated_cost);
        // Valued per NOS, because NOS is the shell's primary unit.
        $this->assertSame('25.000000', $shell->unit_cost);
    }

    #[Test]
    public function the_shell_is_valued_against_its_count_not_its_weight(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '100.000000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot, '200.000', '420.000'));
        $shell = $this->outputFor($posted, $this->shell);

        // 25,000 / 1,000 NOS = 25, not 25,000 / 420 KG = 59.52…
        $this->assertSame('25.000000', $shell->unit_cost);
        $this->assertNotSame(
            DecimalMath::cost(DecimalMath::div($shell->allocated_cost, $shell->secondary_quantity)),
            $shell->unit_cost,
        );

        // And the ledger agrees: stock value ÷ count is the weighted average.
        $this->assertSame('25.000000', $this->stock()->weightedAverageCostForProduct($this->shell->id));
    }

    #[Test]
    public function the_informational_cost_per_kilogram_is_derivable_but_is_not_the_weighted_average(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '100.000000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot, '200.000', '420.000'));
        $shell = $this->outputFor($posted, $this->shell);

        // 25,000 / 420 = 59.523810 — a display figure only.
        $this->assertSame(
            '59.523810',
            DecimalMath::cost(DecimalMath::div($shell->allocated_cost, $shell->secondary_quantity)),
        );
        $this->assertSame('25.000000', $this->stock()->weightedAverageCostForProduct($this->shell->id));
    }

    #[Test]
    public function the_specification_cost_example_of_eighty_seven_thousand_one_hundred_and_twenty_five(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot, '190.500', '420.750'));

        $this->assertSame('87125.000000', $posted->inputValue());
        $this->assertSame('87125.000000', $posted->outputValue());

        $this->assertSame('65343.750000', $this->outputFor($posted, $this->copra)->allocated_cost);
        $this->assertSame('21781.250000', $this->outputFor($posted, $this->shell)->allocated_cost);
    }

    #[Test]
    public function copra_production_neither_creates_nor_destroys_inventory_value(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot, '190.500', '420.750'));

        $this->assertSame($posted->inputValue(), $posted->outputValue());

        $allocated = $posted->outputs->reduce(
            fn (string $carry, ProductionOutput $o): string => bcadd($carry, $o->allocated_cost, 6),
            '0.000000',
        );

        $this->assertSame($posted->inputs->first()->total_cost, $allocated);
    }

    #[Test]
    public function the_rounding_remainder_is_absorbed_so_the_total_is_exact(): void
    {
        // A consumed value that 75/25 cannot split cleanly.
        $lot = $this->lotFor($this->dehusked);
        $this->receive('1000.000', '33.333333', $lot);

        $posted = $this->dry($this->draftOrder('7.000', $lot, '1.100', '2.300'));

        $consumed = $posted->inputs->first()->total_cost;
        $allocated = $posted->outputs->reduce(
            fn (string $carry, ProductionOutput $o): string => bcadd($carry, $o->allocated_cost, 6),
            '0.000000',
        );

        $this->assertSame($consumed, $allocated);
    }

    #[Test]
    public function the_input_weighted_average_is_captured_from_the_ledger(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot));
        $input = $posted->inputs->first();

        $this->assertSame('87.125000', $input->unit_cost);
        $this->assertSame('87125.000000', $input->total_cost);
    }

    #[Test]
    public function a_recipe_that_does_not_allocate_one_hundred_percent_is_rejected(): void
    {
        $recipe = ProductionRecipe::forType(ProductionType::Copra);
        $recipe->outputs()->where('product_id', $this->shell->id)->update(['allocation_percent' => '10.000000']);

        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        try {
            $this->dry($this->draftOrder('1000.000', $lot));
            $this->fail('Expected an 85% recipe to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('5000.000', $this->stock()->quantityForProduct($this->dehusked->id));
        $this->assertSame(1, InventoryTransaction::count());
    }

    #[Test]
    public function no_labour_fuel_or_overhead_cost_is_added(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot));

        // Output value is exactly the consumed value — nothing was added on the way.
        $this->assertSame('87125.000000', $posted->outputValue());

        // The labour columns exist for dehusking; copra carries no labour.
        $this->assertNull($posted->labour_cost);
        foreach ($posted->outputs as $output) {
            $this->assertNull($output->labour_cost);
        }

        foreach (['fuel_cost', 'electricity_cost', 'machine_cost', 'overhead_cost', 'drying_cost'] as $column) {
            $this->assertFalse(Schema::hasColumn('production_orders', $column));
            $this->assertFalse(Schema::hasColumn('production_outputs', $column));
        }
    }

    // ------------------------------------------------------------- pooled (lot-free) input

    #[Test]
    public function pooled_dehusked_coconut_is_processed_without_a_lot_while_copra_and_shell_keep_lots(): void
    {
        $this->dehusked->update(['tracks_lots' => false]);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->dehusked->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: '5000.000',
                unitCost: '87.125000',
            ),
        ]);

        $posted = $this->dry($this->draftOrder('1000.000', null));

        $this->assertNull($posted->inputs->first()->lot_id);
        $this->assertSame('4000.000', $this->stock()->quantityForProduct($this->dehusked->id));

        // Copra and shell still track lots, so each output gets one naming the run.
        foreach ($posted->outputs as $output) {
            $this->assertNotNull($output->lot_id);
            $lot = InventoryLot::findOrFail($output->lot_id);
            $this->assertSame(ProductionOrder::class, $lot->source_type);
            $this->assertStringContainsString('unlotted stock', (string) $lot->notes);
        }
    }

    #[Test]
    public function a_lot_chosen_for_pooled_dehusked_coconut_is_refused(): void
    {
        // Stock received into a lot before the product was pooled.
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $this->dehusked->update(['tracks_lots' => false]);

        try {
            $this->dry($this->draftOrder('1000.000', $lot));
            $this->fail('Expected a lot on pooled stock to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inputs.0.lot_id', $e->errors());
        }

        $this->assertSame('5000.000', $this->stock()->quantityForProduct($this->dehusked->id));
    }

    // ------------------------------------------------------------- output validation

    #[Test]
    public function a_zero_copra_weight_is_rejected(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $this->expectException(ValidationException::class);

        $this->dry($this->draftOrder('1000.000', $lot, '0.000'));
    }

    #[Test]
    public function a_negative_copra_weight_is_rejected(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $this->expectException(ValidationException::class);

        $this->dry($this->draftOrder('1000.000', $lot, '-10.000'));
    }

    #[Test]
    public function a_run_without_a_shell_weight_posts_with_the_shell_count_only(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $order = $this->dry($this->draftOrder('1000.000', $lot, '190.500', null));

        $shell = $order->outputs->firstWhere('product_id', $this->shell->id);
        $this->assertSame('1000.000', $shell->quantity, 'One shell per coconut, as always.');
        $this->assertNull($shell->secondary_quantity, 'No weight is invented from the count.');
    }

    #[Test]
    public function a_zero_shell_weight_is_rejected(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $this->expectException(ValidationException::class);

        $this->dry($this->draftOrder('1000.000', $lot, '190.500', '0.000'));
    }

    #[Test]
    public function a_negative_shell_weight_is_rejected(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $this->expectException(ValidationException::class);

        $this->dry($this->draftOrder('1000.000', $lot, '190.500', '-5.000'));
    }

    #[Test]
    public function a_rejected_shell_weight_leaves_no_stock_behind(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $transactions = InventoryTransaction::count();
        $lots = InventoryLot::count();

        try {
            $this->dry($this->draftOrder('1000.000', $lot, '190.500', '0.000'));
            $this->fail('Expected a zero shell weight to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($transactions, InventoryTransaction::count());
        $this->assertSame($lots, InventoryLot::count());
        $this->assertSame('5000.000', $this->stock()->quantityForProduct($this->dehusked->id));
    }

    #[Test]
    public function an_output_missing_from_the_recipe_is_rejected(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $order = $this->draftOrder('1000.000', $lot);
        $order->outputs()->where('product_id', $this->shell->id)->delete();

        $this->expectException(ValidationException::class);

        $this->dry($order->refresh());
    }

    #[Test]
    public function an_output_product_outside_the_recipe_is_rejected(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $order = $this->draftOrder('1000.000', $lot);
        ProductionOutput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->dehusked->id, // not a copra output
            'quantity' => '10.000',
        ]);

        $this->expectException(ValidationException::class);

        $this->dry($order->refresh());
    }

    // ------------------------------------------------------------- input validation

    #[Test]
    public function an_input_lot_is_required(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $this->expectException(ValidationException::class);

        $this->dry($this->draftOrder('1000.000', null));
    }

    #[Test]
    public function a_lot_belonging_to_another_product_is_rejected(): void
    {
        $coconutLot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $coconutLot);

        $copraLot = $this->lotFor($this->copra);

        $this->expectException(ValidationException::class);

        $this->dry($this->draftOrder('1000.000', $copraLot));
    }

    #[Test]
    public function an_input_product_other_than_dehusked_coconut_is_rejected(): void
    {
        $copraLot = $this->lotFor($this->copra);
        $this->receive('500.000', '300.000000', $copraLot, $this->copra);

        $this->expectException(ValidationException::class);

        $this->dry($this->draftOrder('100.000', $copraLot, inputProduct: $this->copra));
    }

    #[Test]
    public function insufficient_dehusked_coconut_stock_is_rejected(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('100.000', '87.125000', $lot);

        try {
            $this->dry($this->draftOrder('101.000', $lot));
            $this->fail('Expected insufficient stock to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->dehusked->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->copra->id));
    }

    #[Test]
    public function a_zero_input_quantity_is_rejected(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $this->expectException(ValidationException::class);

        $this->dry($this->draftOrder('0.000', $lot));
    }

    #[Test]
    public function a_negative_input_quantity_is_rejected(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $this->expectException(ValidationException::class);

        $this->dry($this->draftOrder('-100.000', $lot));
    }

    #[Test]
    public function an_inactive_warehouse_is_rejected(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $inactive = Warehouse::factory()->inactive()->create(['code' => 'WH-OFF']);

        $this->expectException(ValidationException::class);

        $this->dry($this->draftOrder('100.000', $lot, attributes: ['warehouse_id' => $inactive->id]));
    }

    // ------------------------------------------------------------- lots and traceability

    #[Test]
    public function posting_creates_a_lot_for_the_copra_and_the_shell(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $lotsBefore = InventoryLot::count();
        $posted = $this->dry($this->draftOrder('1000.000', $lot));

        $this->assertSame($lotsBefore + 2, InventoryLot::count());
        $this->assertSame(1, InventoryLot::where('product_id', $this->copra->id)->count());
        $this->assertSame(1, InventoryLot::where('product_id', $this->shell->id)->count());

        foreach ($posted->outputs as $output) {
            $this->assertNotNull($output->lot_id);

            $outputLot = InventoryLot::findOrFail($output->lot_id);
            $this->assertSame($output->product_id, $outputLot->product_id);
            $this->assertSame(ProductionOrder::class, $outputLot->source_type);
            $this->assertSame($posted->id, (int) $outputLot->source_id);
        }
    }

    #[Test]
    public function the_output_lots_trace_back_to_the_dehusked_coconut_lot(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot));

        foreach ($posted->outputs as $output) {
            $notes = (string) InventoryLot::findOrFail($output->lot_id)->notes;

            $this->assertStringContainsString($lot->lot_number, $notes);
            $this->assertStringContainsString($posted->production_number, $notes);
        }

        $this->assertSame($lot->id, $posted->inputs->first()->lot_id);
        $this->assertSame('4000.000', $this->stock()->quantityForLot($lot->id));
    }

    #[Test]
    public function the_ledger_records_both_a_consumption_and_an_output_transaction(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot));

        $this->assertSame(InventoryTransactionType::ProductionConsumption, $posted->consumptionTransaction->transaction_type);
        $this->assertSame(InventoryDirection::Out, $posted->consumptionTransaction->lines->first()->direction);
        $this->assertSame($lot->id, $posted->consumptionTransaction->lines->first()->lot_id);

        $this->assertSame(InventoryTransactionType::ProductionOutput, $posted->outputTransaction->transaction_type);
        $this->assertCount(2, $posted->outputTransaction->lines);
    }

    // ------------------------------------------------------------- atomicity

    #[Test]
    public function a_failed_posting_creates_no_lots_and_no_partial_ledger(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        // Deactivating the shell makes the OUTPUT half fail, after the consumption half has
        // already been written — the whole thing must still roll back.
        $this->shell->update(['is_active' => false]);

        $transactionsBefore = InventoryTransaction::count();
        $lotsBefore = InventoryLot::count();

        $order = $this->draftOrder('1000.000', $lot);

        try {
            $this->dry($order);
            $this->fail('Expected the posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($transactionsBefore, InventoryTransaction::count());
        $this->assertSame($lotsBefore, InventoryLot::count());
        $this->assertSame('5000.000', $this->stock()->quantityForProduct($this->dehusked->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->copra->id));
        $this->assertSame(ProductionStatus::Draft, $order->fresh()->status);
        $this->assertNull($order->fresh()->inputs()->first()->total_cost);
        $this->assertNull($order->fresh()->outputs()->first()->allocated_cost);
    }

    #[Test]
    public function a_production_order_cannot_be_posted_twice(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot));

        try {
            $this->dry($posted);
            $this->fail('Expected double posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('4000.000', $this->stock()->quantityForProduct($this->dehusked->id));
        $this->assertSame('190.500', $this->stock()->quantityForProduct($this->copra->id));
    }

    #[Test]
    public function a_posted_production_order_is_immutable(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot));

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
    public function a_husk_chipping_order_cannot_be_posted_by_the_copra_action(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $this->expectException(ValidationException::class);

        $this->dry($this->draftOrder('100.000', $lot, attributes: [
            'production_type' => ProductionType::HuskChipping,
        ]));
    }

    // ------------------------------------------------------------- reversal

    #[Test]
    public function reversing_restores_the_coconut_and_removes_the_copra_and_shell(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot, '190.500', '420.750'));
        $reversed = app(ReverseProduction::class)->handle($posted);

        $this->assertSame(ProductionStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversed_at);

        $this->assertSame('5000.000', $this->stock()->quantityForProduct($this->dehusked->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->copra->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->shell->id));

        // The measured weight is removed with the count, so neither is left stranded.
        $this->assertSame('0.000', $this->stock()->secondaryQuantityForProduct($this->shell->id));

        $this->assertSame('435625.000000', $this->stock()->valueForProduct($this->dehusked->id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($this->copra->id));
        $this->assertSame('0.000000', $this->stock()->valueForProduct($this->shell->id));
    }

    #[Test]
    public function the_original_production_record_survives_a_reversal(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot, '190.500', '420.750'));
        $reversed = app(ReverseProduction::class)->handle($posted);

        $this->assertSame('190.500', $this->outputFor($reversed, $this->copra)->quantity);
        $this->assertSame('1000.000', $this->outputFor($reversed, $this->shell)->quantity);
        $this->assertSame('420.750', $this->outputFor($reversed, $this->shell)->secondary_quantity);
        $this->assertSame('87125.000000', $reversed->inputValue());
    }

    #[Test]
    public function a_reversal_uses_the_original_production_costs(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '100.000000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot, '200.000', '420.000'));

        // Move the coconut average well away from the original 100.
        $second = $this->lotFor($this->dehusked);
        $this->receive('4000.000', '250.000000', $second);

        $reversed = app(ReverseProduction::class)->handle($posted);

        $consumptionReversal = InventoryTransaction::findOrFail($reversed->reversal_inventory_transaction_id);
        $outputReversal = InventoryTransaction::findOrFail($reversed->output_reversal_transaction_id);

        // Coconut returns at the 100 it left at, not the drifted average.
        $this->assertSame('100.000000', $consumptionReversal->lines->first()->unit_cost);

        $this->assertSame('375.000000', $outputReversal->lines->firstWhere('product_id', $this->copra->id)->unit_cost);
        $this->assertSame('25.000000', $outputReversal->lines->firstWhere('product_id', $this->shell->id)->unit_cost);
    }

    #[Test]
    public function the_reversal_mirrors_the_measured_shell_weight(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot, '190.500', '420.750'));
        $reversed = app(ReverseProduction::class)->handle($posted);

        $outputReversal = InventoryTransaction::findOrFail($reversed->output_reversal_transaction_id);
        $shellLine = $outputReversal->lines->firstWhere('product_id', $this->shell->id);

        $this->assertSame(InventoryDirection::Out, $shellLine->direction);
        $this->assertSame('1000.000', $shellLine->quantity);
        $this->assertSame('420.750', $shellLine->secondary_quantity);
    }

    #[Test]
    public function a_production_order_cannot_be_reversed_twice(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot));
        app(ReverseProduction::class)->handle($posted);

        $this->expectException(ValidationException::class);

        app(ReverseProduction::class)->handle($posted->fresh());
    }

    /**
     * Selling either output must block the whole reversal — there is no partial reversal.
     */
    #[Test]
    public function reversal_is_rejected_when_the_copra_or_the_shell_has_been_consumed(): void
    {
        foreach ([$this->copra, $this->shell] as $product) {
            $lot = $this->lotFor($this->dehusked);
            $this->receive('5000.000', '100.000000', $lot);

            $posted = $this->dry($this->draftOrder('1000.000', $lot, '200.000', '420.000'));

            // Sell most of this output.
            app(PostInventoryTransaction::class)->handle(InventoryTransactionType::SalesIssue, [
                new PendingInventoryLine(
                    productId: $product->id,
                    warehouseId: $this->main->id,
                    direction: InventoryDirection::Out,
                    quantity: '150.000',
                    lotId: $this->outputFor($posted, $product)->lot_id,
                ),
            ]);

            try {
                app(ReverseProduction::class)->handle($posted);
                $this->fail("Expected the reversal to be rejected after {$product->code} was consumed.");
            } catch (ValidationException) {
                // expected
            }

            // Nothing moved — no partial reversal.
            $fresh = $posted->fresh();
            $this->assertSame(ProductionStatus::Posted, $fresh->status, "after {$product->code}");
            $this->assertNull($fresh->reversal_inventory_transaction_id);
            $this->assertNull($fresh->output_reversal_transaction_id);
            $this->assertSame('4000.000', $this->stock()->quantityForLot($lot->id));
        }
    }

    // ------------------------------------------------------------- cancellation & scope

    #[Test]
    public function a_draft_can_be_cancelled_and_creates_no_stock(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $order = $this->draftOrder('1000.000', $lot);
        $cancelled = app(CancelProduction::class)->handle($order);

        $this->assertSame(ProductionStatus::Cancelled, $cancelled->status);
        $this->assertSame('5000.000', $this->stock()->quantityForProduct($this->dehusked->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->copra->id));
    }

    #[Test]
    public function copra_production_creates_no_finance_or_general_ledger_records(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $this->dry($this->draftOrder('1000.000', $lot));

        foreach ([
            'journals', 'journal_lines', 'general_ledger',
            'production_costs', 'labour_costs', 'overheads', 'packaging_costs',
            'payables', 'receivables', 'payments',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table), "Copra production must not create a [{$table}] table.");
        }

        // The accounting foundation exists (Finance Phase 1), but operational documents do
        // not generate journals yet — that integration is a later, deliberate phase.
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalEntryLine::count());
    }

    #[Test]
    public function no_moisture_loss_or_waste_product_is_created(): void
    {
        $lot = $this->lotFor($this->dehusked);
        $this->receive('5000.000', '87.125000', $lot);

        $posted = $this->dry($this->draftOrder('1000.000', $lot));

        // Version 1 models no drying loss at all: two outputs, two lots, no scrap product.
        $this->assertCount(2, $posted->outputTransaction->lines);
        $this->assertSame(0, Product::where('code', 'like', '%MOISTURE%')->count());
        $this->assertSame(0, Product::where('code', 'like', '%WASTE%')->count());
        $this->assertSame(0, Product::where('code', 'like', '%DUST%')->count());
    }

    #[Test]
    public function the_products_table_still_has_no_stock_column(): void
    {
        foreach (['current_stock', 'stock', 'stock_quantity', 'quantity', 'on_hand'] as $column) {
            $this->assertFalse(Schema::hasColumn('products', $column));
        }
    }
}
