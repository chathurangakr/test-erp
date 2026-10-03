<?php

namespace Tests\Feature\Production;

use App\Actions\Accounting\UpdateAccountingSettings;
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
use App\Models\FiscalPeriod;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\ProductionRecipe;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Husk chipping (recipe version 2): the run takes NO raw husk. Its measured outputs enter
 * stock at zero value — raw husk material cost pending backflush — and the husk is deducted
 * when the outputs are delivered. See docs/BUSINESS_RULES.md §19.
 */
class HuskChippingTest extends TestCase
{
    use RefreshDatabase;

    private UnitOfMeasure $kg;

    private Product $husk;

    private Product $c7;

    private Product $c9;

    private Product $c10;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        // The chip grades are created here (lot-tracked, as factories are); Cocopeat and the
        // litre stock items are created by seedProductionRecipes() from the seeded master data.
        $this->husk = Product::factory()->create(['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary_uom_id' => $nos->id]);
        $this->c7 = Product::factory()->create(['code' => 'HUSK-7C', 'name' => '7C', 'primary_uom_id' => $this->kg->id]);
        $this->c9 = Product::factory()->create(['code' => 'HUSK-9C', 'name' => '9C', 'primary_uom_id' => $this->kg->id]);
        $this->c10 = Product::factory()->create(['code' => 'HUSK-10C', 'name' => '10C', 'primary_uom_id' => $this->kg->id]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);

        $this->seedProductionRecipes();
    }

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    private function product(string $code): Product
    {
        return Product::query()->where('code', $code)->firstOrFail();
    }

    /** Put raw husk on hand at a known cost. */
    private function receiveHusk(string $quantity, string $unitCost): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->husk->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: app(CreateInventoryLot::class)->handle($this->husk)->id,
                unitCost: $unitCost,
            ),
        ]);
    }

    /**
     * @param  array<string, string>  $outputs  product code => measured quantity
     * @param  array<string, mixed>  $attributes
     */
    private function draftOrder(array $outputs = [], array $attributes = []): ProductionOrder
    {
        $order = ProductionOrder::factory()->huskChipping()->create(array_merge([
            'warehouse_id' => $this->main->id,
        ], $attributes));

        foreach ($outputs ?: ['HUSK-7C' => '150.500', 'HUSK-9C' => '205.250', 'HUSK-10C' => '281.750'] as $code => $quantity) {
            ProductionOutput::factory()->create([
                'production_order_id' => $order->id,
                'product_id' => $this->product($code)->id,
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

    /**
     * Posting must fail with an error on the given key, and leave no stock movement behind.
     */
    private function assertRefused(ProductionOrder $order, string $key): void
    {
        $before = InventoryTransaction::count();

        try {
            $this->chip($order);
            $this->fail("Expected the posting to be refused on [{$key}].");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }

        $this->assertSame($before, InventoryTransaction::count());
        $this->assertSame(ProductionStatus::Draft, $order->fresh()->status);
    }

    // ------------------------------------------------------------- the recipe

    #[Test]
    public function the_recipe_backflushes_raw_husk_at_delivery_with_no_conversion(): void
    {
        $recipe = ProductionRecipe::forType(ProductionType::HuskChipping);

        $this->assertNotNull($recipe);
        $this->assertTrue($recipe->consumesInputAtDelivery());
        $this->assertSame($this->husk->id, $recipe->input_product_id);

        $codes = $recipe->outputs->map(fn ($output): string => $output->product->code)->all();
        $this->assertSame([
            'HUSK-7C', 'HUSK-9C', 'HUSK-10C', 'COCOPEAT',
            'HUSK-7C-L', 'HUSK-9C-L', 'HUSK-10C-L', 'COCOPEAT-L',
        ], $codes);

        // No ratio anywhere: nothing exists to derive a weight or a volume from a husk count.
        foreach ($recipe->outputs as $output) {
            $this->assertNull($output->output_ratio);
        }

        $this->assertSame('L', $this->product('COCOPEAT-L')->primaryUom->code);
        $this->assertSame('KG', $this->product('COCOPEAT')->primaryUom->code);
    }

    // ------------------------------------------------------------- drafts

    #[Test]
    public function a_draft_has_outputs_and_no_raw_husk_input(): void
    {
        $order = $this->draftOrder();

        $this->assertSame(ProductionStatus::Draft, $order->status);
        $this->assertSame(ProductionType::HuskChipping, $order->production_type);
        $this->assertCount(0, $order->inputs);
        $this->assertCount(3, $order->outputs);
    }

    #[Test]
    public function a_draft_creates_no_inventory_movement(): void
    {
        $this->receiveHusk('6000', '19.500000');
        $before = InventoryTransaction::count();

        $this->draftOrder();

        $this->assertSame($before, InventoryTransaction::count());
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->c7->id));
    }

    // ------------------------------------------------------------- posting

    #[Test]
    public function posting_brings_the_measured_outputs_into_stock_and_takes_no_raw_husk(): void
    {
        $this->receiveHusk('6000', '19.500000');

        $this->chip($this->draftOrder([
            'HUSK-7C' => '150.500', 'HUSK-9C' => '205.250', 'HUSK-10C' => '281.750',
        ]));

        // The husk is untouched — in quantity AND in value. It leaves at delivery instead.
        $this->assertSame('6000.000', $this->stock()->quantityForProduct($this->husk->id));
        $this->assertSame('117000.000000', $this->stock()->valueForProduct($this->husk->id));

        $this->assertSame('150.500', $this->stock()->quantityForProduct($this->c7->id));
        $this->assertSame('205.250', $this->stock()->quantityForProduct($this->c9->id));
        $this->assertSame('281.750', $this->stock()->quantityForProduct($this->c10->id));
    }

    #[Test]
    public function posting_works_with_no_raw_husk_in_stock_at_all(): void
    {
        $posted = $this->chip($this->draftOrder(['HUSK-7C' => '40.000']));

        $this->assertSame(ProductionStatus::Posted, $posted->status);
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->husk->id));
        $this->assertSame('40.000', $this->stock()->quantityForProduct($this->c7->id));
    }

    #[Test]
    public function outputs_enter_stock_at_zero_value_pending_backflush(): void
    {
        $this->receiveHusk('6000', '20.000000');

        $posted = $this->chip($this->draftOrder());

        foreach ($posted->outputs as $output) {
            $this->assertSame('0.000000', $output->allocation_percent);
            $this->assertSame('0.000000', $output->allocated_cost);
            $this->assertSame('0.000000', $output->unit_cost);
            $this->assertNull($output->labour_cost);
        }

        foreach ($posted->outputTransaction->lines as $line) {
            $this->assertSame('0.000000', $line->unit_cost);
            $this->assertSame('0.000000', $line->total_cost);
        }

        $this->assertSame('0.000000', $this->stock()->valueForProduct($this->c7->id));
        $this->assertSame('0.000000', $posted->outputValue());
        $this->assertSame(
            ProductionRecipe::forType(ProductionType::HuskChipping)->id,
            $posted->production_recipe_id,
        );
    }

    #[Test]
    public function only_one_output_transaction_is_written(): void
    {
        $this->receiveHusk('6000', '20.000000');
        $before = InventoryTransaction::count();

        $posted = $this->chip($this->draftOrder());

        $this->assertSame($before + 1, InventoryTransaction::count());
        $this->assertNull($posted->inventory_transaction_id);
        $this->assertSame(InventoryTransactionType::ProductionOutput, $posted->outputTransaction->transaction_type);
        $this->assertCount(3, $posted->outputTransaction->lines);
        $this->assertTrue($posted->outputTransaction->lines->every(
            fn ($line): bool => $line->direction === InventoryDirection::In,
        ));
    }

    #[Test]
    public function kilogram_and_litre_stock_of_the_same_grade_stay_separate(): void
    {
        $this->chip($this->draftOrder([
            'HUSK-7C' => '50.000',
            'HUSK-7C-L' => '100.000',
            'COCOPEAT-L' => '100.000',
        ]));

        $this->assertSame('50.000', $this->stock()->quantityForProduct($this->c7->id));
        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->product('HUSK-7C-L')->id));
        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->product('COCOPEAT-L')->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->product('COCOPEAT')->id));

        // A second run adds to each balance on its own; nothing nets or converts.
        $this->chip($this->draftOrder(['HUSK-7C-L' => '20.000']));

        $this->assertSame('50.000', $this->stock()->quantityForProduct($this->c7->id));
        $this->assertSame('120.000', $this->stock()->quantityForProduct($this->product('HUSK-7C-L')->id));
    }

    #[Test]
    public function a_grade_not_produced_is_simply_left_out(): void
    {
        $posted = $this->chip($this->draftOrder(['HUSK-7C' => '40.000', 'COCOPEAT' => '12.500']));

        $this->assertCount(2, $posted->outputTransaction->lines);
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->c9->id));
        $this->assertSame('12.500', $this->stock()->quantityForProduct($this->product('COCOPEAT')->id));
    }

    // ------------------------------------------------------------- refusals

    #[Test]
    public function a_zero_row_is_refused(): void
    {
        $this->assertRefused(
            $this->draftOrder(['HUSK-7C' => '40.000', 'HUSK-9C' => '0.000']),
            'outputs.1.quantity',
        );
    }

    #[Test]
    public function a_negative_row_is_refused(): void
    {
        $this->assertRefused(
            $this->draftOrder(['HUSK-7C' => '-10.000', 'HUSK-9C' => '100.000']),
            'outputs.0.quantity',
        );
    }

    #[Test]
    public function a_run_with_no_outputs_is_refused(): void
    {
        $order = ProductionOrder::factory()->huskChipping()->create(['warehouse_id' => $this->main->id]);

        $this->assertRefused($order, 'outputs');
    }

    #[Test]
    public function a_product_appearing_twice_is_refused(): void
    {
        $order = $this->draftOrder(['HUSK-7C' => '40.000']);
        ProductionOutput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->c7->id,
            'quantity' => '10.000',
        ]);

        $this->assertRefused($order->refresh(), 'outputs.1.product_id');
    }

    #[Test]
    public function an_output_product_outside_the_recipe_is_refused(): void
    {
        $order = $this->draftOrder(['HUSK-7C' => '40.000']);
        ProductionOutput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->husk->id, // the input, not an output
            'quantity' => '10.000',
        ]);

        $this->assertRefused($order->refresh(), 'outputs.1.product_id');
    }

    #[Test]
    public function a_draft_from_the_old_method_with_a_husk_input_is_refused(): void
    {
        $this->receiveHusk('6000', '20.000000');
        $order = $this->draftOrder();

        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->husk->id,
            'quantity' => '2000.000',
        ]);

        $this->assertRefused($order->refresh(), 'inputs');
        $this->assertSame('6000.000', $this->stock()->quantityForProduct($this->husk->id));
    }

    #[Test]
    public function an_inactive_warehouse_is_refused(): void
    {
        $inactive = Warehouse::factory()->inactive()->create(['code' => 'WH-OFF']);

        $this->assertRefused($this->draftOrder([], ['warehouse_id' => $inactive->id]), 'warehouse_id');
    }

    #[Test]
    public function a_dehusking_order_cannot_be_posted_by_the_chipping_action(): void
    {
        $this->assertRefused(
            $this->draftOrder([], ['production_type' => ProductionType::Dehusking]),
            'production_type',
        );
    }

    // ------------------------------------------------------------- lots

    #[Test]
    public function a_lot_tracked_output_gets_a_lot_that_names_the_run(): void
    {
        // The factory-made grades track lots; the seeded Cocopeat is pooled.
        $posted = $this->chip($this->draftOrder(['HUSK-7C' => '40.000', 'COCOPEAT' => '12.000']));

        $chips = $posted->outputs->firstWhere('product_id', $this->c7->id);
        $lot = InventoryLot::findOrFail($chips->lot_id);
        $this->assertSame($this->c7->id, $lot->product_id);
        $this->assertSame(ProductionOrder::class, $lot->source_type);
        $this->assertSame($posted->id, (int) $lot->source_id);

        $cocopeat = $posted->outputs->firstWhere('product_id', $this->product('COCOPEAT')->id);
        $this->assertNull($cocopeat->lot_id);
        $this->assertSame(0, InventoryLot::where('product_id', $this->product('COCOPEAT')->id)->count());
    }

    // ------------------------------------------------------------- waste

    #[Test]
    public function waste_is_recorded_as_information_and_creates_no_inventory(): void
    {
        $order = $this->draftOrder(['HUSK-7C' => '150.000', 'HUSK-9C' => '200.000']);
        $order->update(['waste_quantity' => '42.500', 'waste_uom_id' => $this->kg->id]);

        $posted = $this->chip($order->refresh());

        $this->assertSame('42.500', $posted->waste_quantity);
        $this->assertSame($this->kg->id, $posted->waste_uom_id);
        $this->assertCount(2, $posted->outputTransaction->lines);
        $this->assertSame(0, Product::where('code', 'like', '%DUST%')->count());
        $this->assertSame(0, Product::where('code', 'like', '%WASTE%')->count());
    }

    #[Test]
    public function waste_is_optional(): void
    {
        $posted = $this->chip($this->draftOrder());

        $this->assertNull($posted->waste_quantity);
        $this->assertNull($posted->waste_uom_id);
        $this->assertSame(ProductionStatus::Posted, $posted->status);
    }

    // ------------------------------------------------------------- atomicity

    #[Test]
    public function a_failed_posting_creates_no_lots_and_no_ledger(): void
    {
        // The second output is inactive, so it may not receive new stock — after the first
        // output's lot has already been created. Everything must roll back.
        $this->c10->update(['is_active' => false]);

        $lotsBefore = InventoryLot::count();
        $order = $this->draftOrder(['HUSK-7C' => '40.000', 'HUSK-10C' => '30.000']);

        try {
            $this->chip($order);
            $this->fail('Expected the posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame($lotsBefore, InventoryLot::count());
        $this->assertSame(ProductionStatus::Draft, $order->fresh()->status);
        $this->assertNull($order->fresh()->outputs()->first()->allocated_cost);
    }

    #[Test]
    public function a_production_order_cannot_be_posted_twice(): void
    {
        $posted = $this->chip($this->draftOrder());

        try {
            $this->chip($posted);
            $this->fail('Expected double posting to be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame('150.500', $this->stock()->quantityForProduct($this->c7->id));
    }

    #[Test]
    public function a_posted_production_order_is_immutable(): void
    {
        $posted = $this->chip($this->draftOrder());

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

    // ------------------------------------------------------------- reversal

    #[Test]
    public function reversing_removes_the_outputs_and_leaves_the_raw_husk_alone(): void
    {
        $this->receiveHusk('6000', '19.500000');

        $posted = $this->chip($this->draftOrder());
        $reversed = app(ReverseProduction::class)->handle($posted);

        $this->assertSame(ProductionStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->output_reversal_transaction_id);
        // There was no consumption, so there is nothing to put back.
        $this->assertNull($reversed->reversal_inventory_transaction_id);

        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->c7->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->c9->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->c10->id));
        $this->assertSame('6000.000', $this->stock()->quantityForProduct($this->husk->id));
        $this->assertSame('117000.000000', $this->stock()->valueForProduct($this->husk->id));
    }

    #[Test]
    public function a_production_order_cannot_be_reversed_twice(): void
    {
        $posted = $this->chip($this->draftOrder());
        app(ReverseProduction::class)->handle($posted);

        $this->expectException(ValidationException::class);

        app(ReverseProduction::class)->handle($posted->fresh());
    }

    #[Test]
    public function reversal_is_refused_once_an_output_has_been_issued(): void
    {
        $posted = $this->chip($this->draftOrder(['HUSK-7C' => '150.000', 'HUSK-9C' => '200.000']));

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::SalesIssue, [
            new PendingInventoryLine(
                productId: $this->c7->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::Out,
                quantity: '100.000',
                lotId: $posted->outputs->firstWhere('product_id', $this->c7->id)->lot_id,
            ),
        ]);

        try {
            app(ReverseProduction::class)->handle($posted);
            $this->fail('Expected the reversal to be refused.');
        } catch (ValidationException) {
            // expected
        }

        $fresh = $posted->fresh();
        $this->assertSame(ProductionStatus::Posted, $fresh->status);
        $this->assertNull($fresh->output_reversal_transaction_id);
        $this->assertSame('200.000', $this->stock()->quantityForProduct($this->c9->id));
    }

    // ------------------------------------------------------------- cancellation & scope

    #[Test]
    public function a_draft_can_be_cancelled_and_creates_no_stock(): void
    {
        $cancelled = app(CancelProduction::class)->handle($this->draftOrder());

        $this->assertSame(ProductionStatus::Cancelled, $cancelled->status);
        $this->assertSame(0, InventoryTransaction::count());
    }

    #[Test]
    public function chipping_writes_no_journal_even_when_accounting_applies(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);

        $posted = $this->chip($this->draftOrder([], ['production_date' => '2026-09-20']));

        // Nothing of value moved — the husk value stays in raw husk until backflushed.
        $this->assertSame(ProductionStatus::Posted, $posted->status);
        $this->assertSame(0, JournalEntry::count());
    }

    #[Test]
    public function the_products_table_still_has_no_stock_column(): void
    {
        foreach (['current_stock', 'stock', 'stock_quantity', 'quantity', 'on_hand'] as $column) {
            $this->assertFalse(Schema::hasColumn('products', $column));
        }
    }
}
