<?php

namespace Tests\Feature\Production;

use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionStatus;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The copra production screens.
 *
 * The screens must refuse a shell count as firmly as the posting action recalculates it,
 * and must never accept a cost figure of any kind.
 */
class CopraScreensTest extends TestCase
{
    use RefreshDatabase;

    private Product $dehusked;

    private Product $copra;

    private Product $shell;

    private UnitOfMeasure $kg;

    private Warehouse $main;

    private InventoryLot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => $nos->id]);
        Product::factory()->create(['code' => 'RAW-HUSK', 'primary_uom_id' => $nos->id]);
        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            Product::factory()->create(['code' => $code, 'primary_uom_id' => $this->kg->id]);
        }

        $this->dehusked = Product::factory()->create([
            'code' => 'DEHUSKED-COCONUT',
            'name' => 'Dehusked Coconut',
            'primary_uom_id' => $nos->id,
        ]);
        $this->copra = Product::factory()->create([
            'code' => 'COPRA',
            'name' => 'Copra',
            'primary_uom_id' => $this->kg->id,
        ]);
        $this->shell = Product::factory()->create([
            'code' => 'RAW-SHELL',
            'name' => 'Raw Coconut Shell',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => $this->kg->id,
        ]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->seedProductionRecipes();

        $this->lot = app(CreateInventoryLot::class)->handle($this->dehusked);
    }

    private function receive(string $quantity, string $unitCost): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->dehusked->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $this->lot->id,
                unitCost: $unitCost,
            ),
        ]);
    }

    /**
     * A valid submission: the copra weight and the shell weight, and no shell count.
     *
     * @return array<string, mixed>
     */
    private function payload(
        string $copraKg = '200.000',
        string $shellKg = '420.000',
        array $extra = [],
    ): array {
        return array_merge([
            'production_date' => now()->toDateString(),
            'warehouse_id' => $this->main->id,
            'lot_id' => $this->lot->id,
            'quantity' => '1000',
            'outputs' => [
                ['product_id' => $this->copra->id, 'quantity' => $copraKg],
                ['product_id' => $this->shell->id, 'secondary_quantity' => $shellKg],
            ],
        ], $extra);
    }

    #[Test]
    public function a_guest_cannot_access_the_copra_screens(): void
    {
        $order = ProductionOrder::factory()->copra()->create(['warehouse_id' => $this->main->id]);

        $this->get(route('copra.index'))->assertRedirect(route('login'));
        $this->get(route('copra.create'))->assertRedirect(route('login'));
        $this->get(route('copra.show', $order))->assertRedirect(route('login'));
        $this->post(route('copra.store'), [])->assertRedirect(route('login'));
        $this->patch(route('copra.post', $order))->assertRedirect(route('login'));
        $this->patch(route('copra.reverse', $order))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_can_access_the_copra_screens(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('copra.index'))->assertOk();
        $this->actingAs($user)->get(route('copra.create'))->assertOk();
    }

    #[Test]
    public function the_create_screen_describes_which_outputs_are_measured_and_which_derived(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '87.125000');

        $this->actingAs($user)
            ->get(route('copra.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/copra/Create')
                ->where('recipe.input_code', 'DEHUSKED-COCONUT')
                ->where('recipe.input_uom', 'NOS')
                ->has('recipe.outputs', 2)
                // Copra: measured, so no ratio exists to derive a weight from.
                ->where('recipe.outputs.0.code', 'COPRA')
                ->where('recipe.outputs.0.uom', 'KG')
                ->where('recipe.outputs.0.ratio', null)
                ->where('recipe.outputs.0.requires_secondary_quantity', false)
                ->where('recipe.outputs.0.allocation_percent', '75.000000')
                // Shell: one per coconut, weighed separately.
                ->where('recipe.outputs.1.code', 'RAW-SHELL')
                ->where('recipe.outputs.1.uom', 'NOS')
                ->where('recipe.outputs.1.secondary_uom', 'KG')
                ->where('recipe.outputs.1.ratio', '1.000000')
                ->where('recipe.outputs.1.requires_secondary_quantity', false)
                ->where('recipe.outputs.1.records_weight', true)
                ->where('recipe.outputs.1.allocation_percent', '25.000000')
                // The estimate figures, so the screen can show a consumed value.
                ->where('inputStock', '5000.000')
                ->where('inputUnitCost', '87.125000')
                ->has('lots', 1));
    }

    #[Test]
    public function a_draft_created_through_the_screen_moves_no_stock(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '87.125000');

        $this->actingAs($user)
            ->post(route('copra.store'), $this->payload('190.500', '420.750'))
            ->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();

        $this->assertMatchesRegularExpression('/^PRD-\d{8}-\d{6}$/', $order->production_number);
        $this->assertSame(ProductionStatus::Draft, $order->status);

        // The input product came from the recipe, not the request.
        $this->assertSame($this->dehusked->id, $order->inputs()->first()->product_id);

        $outputs = $order->outputs()->get()->keyBy('product_id');

        $this->assertSame('190.500', $outputs[$this->copra->id]->quantity);
        $this->assertNull($outputs[$this->copra->id]->secondary_quantity);

        // The shell count was calculated from the coconuts processed.
        $this->assertSame('1000.000', $outputs[$this->shell->id]->quantity);
        $this->assertSame('420.750', $outputs[$this->shell->id]->secondary_quantity);

        // Only the purchase receipt exists.
        $this->assertSame(1, InventoryTransaction::count());
        $this->assertSame('5000.000', app(StockQueryService::class)->quantityForProduct($this->dehusked->id));
    }

    #[Test]
    public function the_screen_refuses_a_typed_shell_count(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '87.125000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload(extra: [
            'outputs' => [
                ['product_id' => $this->copra->id, 'quantity' => '200.000'],
                ['product_id' => $this->shell->id, 'quantity' => '9999.000', 'secondary_quantity' => '420.000'],
            ],
        ]))->assertSessionHasErrors('outputs.1.quantity');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function the_screen_requires_the_copra_weight(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '87.125000');

        // Missing.
        $this->actingAs($user)->post(route('copra.store'), $this->payload(extra: [
            'outputs' => [
                ['product_id' => $this->copra->id],
                ['product_id' => $this->shell->id, 'secondary_quantity' => '420.000'],
            ],
        ]))->assertSessionHasErrors('outputs.0.quantity');

        // Zero.
        $this->actingAs($user)->post(route('copra.store'), $this->payload('0'))
            ->assertSessionHasErrors('outputs.0.quantity');

        // Negative.
        $this->actingAs($user)->post(route('copra.store'), $this->payload('-1.000'))
            ->assertSessionHasErrors('outputs.0.quantity');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function the_shell_weight_is_optional_but_an_entered_weight_must_be_positive(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '87.125000');

        // Zero.
        $this->actingAs($user)->post(route('copra.store'), $this->payload(shellKg: '0'))
            ->assertSessionHasErrors('outputs.1.secondary_quantity');

        // Negative.
        $this->actingAs($user)->post(route('copra.store'), $this->payload(shellKg: '-1.000'))
            ->assertSessionHasErrors('outputs.1.secondary_quantity');

        $this->assertSame(0, ProductionOrder::count());

        // Missing: allowed — the factory has no scale for shell.
        $this->actingAs($user)->post(route('copra.store'), $this->payload(extra: [
            'outputs' => [
                ['product_id' => $this->copra->id, 'quantity' => '200.000'],
                ['product_id' => $this->shell->id],
            ],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, ProductionOrder::count());
    }

    #[Test]
    public function a_weight_cannot_be_recorded_against_an_output_that_has_no_secondary_unit(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '87.125000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload(extra: [
            'outputs' => [
                ['product_id' => $this->copra->id, 'quantity' => '200.000', 'secondary_quantity' => '50.000'],
                ['product_id' => $this->shell->id, 'secondary_quantity' => '420.000'],
            ],
        ]))->assertSessionHasErrors('outputs.0.secondary_quantity');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function every_output_must_be_accounted_for(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '87.125000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload(extra: [
            'outputs' => [
                ['product_id' => $this->copra->id, 'quantity' => '200.000'],
            ],
        ]))->assertSessionHasErrors('outputs');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function a_product_outside_the_recipe_is_refused_by_the_screen(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '87.125000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload(extra: [
            'outputs' => [
                ['product_id' => $this->copra->id, 'quantity' => '200.000'],
                ['product_id' => $this->shell->id, 'secondary_quantity' => '420.000'],
                ['product_id' => $this->dehusked->id, 'quantity' => '10.000'],
            ],
        ]))->assertSessionHasErrors('outputs.2.product_id');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function the_screen_requires_a_lot_and_a_positive_quantity(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '87.125000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload(extra: ['lot_id' => null]))
            ->assertSessionHasErrors('lot_id');

        $this->actingAs($user)->post(route('copra.store'), $this->payload(extra: ['quantity' => '0']))
            ->assertSessionHasErrors('quantity');

        $this->actingAs($user)->post(route('copra.store'), $this->payload(extra: ['quantity' => '-5']))
            ->assertSessionHasErrors('quantity');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function pooled_dehusked_coconut_is_entered_without_a_lot_and_a_lot_is_refused(): void
    {
        $user = User::factory()->admin()->create();
        $this->dehusked->update(['tracks_lots' => false]);

        $this->actingAs($user)->post(route('copra.store'), $this->payload())
            ->assertSessionHasErrors('lot_id');

        $this->assertSame(0, ProductionOrder::count());

        $this->actingAs($user)->post(route('copra.store'), $this->payload(extra: ['lot_id' => null]))
            ->assertSessionHasNoErrors();

        $this->assertNull(ProductionOrder::firstOrFail()->inputs()->first()->lot_id);
    }

    #[Test]
    public function the_screen_refuses_an_inactive_warehouse(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '87.125000');

        $inactive = Warehouse::factory()->inactive()->create(['code' => 'WH-OFF']);

        $this->actingAs($user)
            ->post(route('copra.store'), $this->payload(extra: ['warehouse_id' => $inactive->id]))
            ->assertSessionHasErrors('warehouse_id');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function the_screen_never_accepts_a_cost(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '100.000000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload(extra: [
            'unit_cost' => '0.000001',
            'total_cost' => '1.000000',
            'outputs' => [
                ['product_id' => $this->copra->id, 'quantity' => '200.000', 'allocated_cost' => '999999.000000'],
                ['product_id' => $this->shell->id, 'secondary_quantity' => '420.000', 'unit_cost' => '1.000000'],
            ],
        ]))->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();

        foreach ($order->outputs as $output) {
            $this->assertNull($output->allocated_cost);
            $this->assertNull($output->unit_cost);
        }

        $this->assertNull($order->inputs()->first()->unit_cost);
        $this->assertNull($order->inputs()->first()->total_cost);

        $this->actingAs($user)->patch(route('copra.post', $order));

        // Costs came from the ledger, not from the request.
        $posted = $order->fresh(['inputs', 'outputs']);
        $this->assertSame('100000.000000', $posted->inputValue());
        $this->assertSame('100000.000000', $posted->outputValue());
    }

    #[Test]
    public function posting_through_the_screen_converts_stock_and_conserves_value(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '100.000000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload('200.000', '420.000'));
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)
            ->patch(route('copra.post', $order))
            ->assertSessionHasNoErrors();

        $stock = app(StockQueryService::class);

        $this->assertSame('4000.000', $stock->quantityForProduct($this->dehusked->id));
        $this->assertSame('200.000', $stock->quantityForProduct($this->copra->id));
        $this->assertSame('1000.000', $stock->quantityForProduct($this->shell->id));
        $this->assertSame('420.000', $stock->secondaryQuantityForProduct($this->shell->id));

        $posted = $order->fresh(['inputs', 'outputs']);
        $this->assertSame(ProductionStatus::Posted, $posted->status);
        $this->assertSame($user->id, $posted->posted_by);
        $this->assertSame('100000.000000', $posted->inputValue());
        $this->assertSame('100000.000000', $posted->outputValue());
    }

    #[Test]
    public function the_show_screen_hides_costs_until_the_run_is_posted(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '100.000000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload('200.000', '420.000'));
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)
            ->get(route('copra.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/copra/Show')
                ->where('order.status', 'DRAFT')
                ->where('order.can_post', true)
                ->where('order.input_value', null)
                ->where('order.output_value', null)
                ->has('order.outputs', 2)
                ->where('order.outputs.0.allocated_cost', null)
                ->where('order.outputs.0.lot_number', null)
                ->where('order.outputs.1.quantity', '1000.000')
                ->where('order.outputs.1.secondary_quantity', '420.000')
                ->where('order.outputs.1.informational_secondary_unit_cost', null));

        $this->actingAs($user)->patch(route('copra.post', $order));

        $this->actingAs($user)
            ->get(route('copra.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.status', 'POSTED')
                ->where('order.input_value', '100000.000000')
                ->where('order.output_value', '100000.000000')
                ->where('order.input.unit_cost', '100.000000')
                ->where('order.input.lot_number', $this->lot->lot_number)
                // Copra: 75% of 100,000 over 200 KG.
                ->where('order.outputs.0.product_code', 'COPRA')
                ->where('order.outputs.0.allocation_percent', '75.000000')
                ->where('order.outputs.0.allocated_cost', '75000.000000')
                ->where('order.outputs.0.unit_cost', '375.000000')
                ->where('order.outputs.0.informational_secondary_unit_cost', null)
                // Shell: 25% of 100,000 over 1,000 NOS — never over its 420 KG.
                ->where('order.outputs.1.product_code', 'RAW-SHELL')
                ->where('order.outputs.1.allocation_percent', '25.000000')
                ->where('order.outputs.1.allocated_cost', '25000.000000')
                ->where('order.outputs.1.unit_cost', '25.000000')
                ->where('order.outputs.1.informational_secondary_unit_cost', '59.523810'));
    }

    #[Test]
    public function the_index_lists_both_output_quantities_and_the_measured_weight(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '100.000000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload('200.000', '420.000'));
        $order = ProductionOrder::firstOrFail();
        $this->actingAs($user)->patch(route('copra.post', $order));

        $this->actingAs($user)
            ->get(route('copra.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/copra/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.input_quantity', '1000.000')
                ->where('orders.data.0.input_value', '100000.000000')
                ->where('orders.data.0.output_value', '100000.000000')
                ->where("orders.data.0.outputs_by_product.{$this->copra->id}.quantity", '200.000')
                ->where("orders.data.0.outputs_by_product.{$this->shell->id}.quantity", '1000.000')
                ->where("orders.data.0.outputs_by_product.{$this->shell->id}.secondary_quantity", '420.000')
                // The columns follow the recipe, so no product code lives in the screen.
                ->has('outputColumns', 2)
                ->where('outputColumns.1.records_weight', true));
    }

    #[Test]
    public function the_index_can_be_filtered_by_status_warehouse_and_date(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '100.000000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload('200.000', '420.000'));

        $other = Warehouse::factory()->create(['code' => 'WH-2']);

        $this->actingAs($user)
            ->get(route('copra.index', ['status' => 'POSTED']))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 0));

        $this->actingAs($user)
            ->get(route('copra.index', ['status' => 'DRAFT', 'warehouse_id' => $this->main->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 1));

        $this->actingAs($user)
            ->get(route('copra.index', ['warehouse_id' => $other->id]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 0));

        $this->actingAs($user)
            ->get(route('copra.index', ['from' => now()->addDay()->toDateString()]))
            ->assertInertia(fn (AssertableInertia $page) => $page->has('orders.data', 0));
    }

    #[Test]
    public function a_draft_can_be_remeasured_through_the_screen(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '100.000000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload('200.000', '420.000'));
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)
            ->get(route('copra.edit', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/copra/Edit')
                ->has('order.outputs', 2)
                ->where('order.outputs.0.quantity', '200.000')
                ->where('order.outputs.1.secondary_quantity', '420.000'));

        $this->actingAs($user)->put(route('copra.update', $order), $this->payload('188.250', '433.500', [
            'quantity' => '900',
        ]))->assertSessionHasNoErrors();

        $outputs = $order->fresh()->outputs()->get()->keyBy('product_id');

        $this->assertSame('188.250', $outputs[$this->copra->id]->quantity);
        // The shell count followed the new coconut count without being typed.
        $this->assertSame('900.000', $outputs[$this->shell->id]->quantity);
        $this->assertSame('433.500', $outputs[$this->shell->id]->secondary_quantity);
    }

    #[Test]
    public function a_posted_run_cannot_be_edited_through_the_screen(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '100.000000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload('200.000', '420.000'));
        $order = ProductionOrder::firstOrFail();
        $this->actingAs($user)->patch(route('copra.post', $order));

        $this->actingAs($user)->get(route('copra.edit', $order))->assertSessionHasErrors('status');
        $this->actingAs($user)->put(route('copra.update', $order), $this->payload())->assertSessionHasErrors('status');
    }

    #[Test]
    public function reversing_through_the_screen_restores_the_coconut(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '100.000000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload('200.000', '420.000'));
        $order = ProductionOrder::firstOrFail();
        $this->actingAs($user)->patch(route('copra.post', $order));

        $this->actingAs($user)
            ->patch(route('copra.reverse', $order))
            ->assertSessionHasNoErrors();

        $stock = app(StockQueryService::class);

        $this->assertSame(ProductionStatus::Reversed, $order->fresh()->status);
        $this->assertSame('5000.000', $stock->quantityForProduct($this->dehusked->id));
        $this->assertSame('0.000', $stock->quantityForProduct($this->copra->id));
        $this->assertSame('0.000', $stock->quantityForProduct($this->shell->id));
        $this->assertSame('0.000', $stock->secondaryQuantityForProduct($this->shell->id));
    }

    #[Test]
    public function a_draft_can_be_cancelled_through_the_screen(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('5000.000', '100.000000');

        $this->actingAs($user)->post(route('copra.store'), $this->payload('200.000', '420.000'));
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)
            ->patch(route('copra.cancel', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame(ProductionStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(1, InventoryTransaction::count());
        $this->assertSame(1, InventoryLot::count());
    }

    #[Test]
    public function there_is_no_destroy_route_for_a_production_order(): void
    {
        $this->assertFalse(app('router')->has('copra.destroy'));
    }
}
