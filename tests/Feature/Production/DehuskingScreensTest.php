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
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DehuskingScreensTest extends TestCase
{
    use RefreshDatabase;

    private Product $coconut;

    private Product $dehusked;

    private Product $husk;

    private Warehouse $main;

    private InventoryLot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);

        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary_uom_id' => $nos->id]);
        $this->dehusked = Product::factory()->create(['code' => 'DEHUSKED-COCONUT', 'name' => 'Dehusked Coconut', 'primary_uom_id' => $nos->id]);
        $this->husk = Product::factory()->create(['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary_uom_id' => $nos->id]);

        // The recipe seeder configures every production type at once, so the husk chipping
        // and copra products must exist too.
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);
        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            Product::factory()->create(['code' => $code, 'primary_uom_id' => $kg->id]);
        }

        Product::factory()->create(['code' => 'COPRA', 'primary_uom_id' => $kg->id]);
        Product::factory()->create([
            'code' => 'RAW-SHELL',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => $kg->id,
        ]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->seedProductionRecipes();

        $this->lot = app(CreateInventoryLot::class)->handle($this->coconut);
    }

    private function receive(string $quantity, string $unitCost): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->coconut->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $this->lot->id,
                unitCost: $unitCost,
            ),
        ]);
    }

    #[Test]
    public function a_guest_cannot_access_the_production_screens(): void
    {
        $order = ProductionOrder::factory()->create(['warehouse_id' => $this->main->id, 'labour_rate' => '2.500000']);

        $this->get(route('dehusking.index'))->assertRedirect(route('login'));
        $this->get(route('dehusking.create'))->assertRedirect(route('login'));
        $this->get(route('dehusking.show', $order))->assertRedirect(route('login'));
        $this->post(route('dehusking.store'), [])->assertRedirect(route('login'));
        $this->patch(route('dehusking.post', $order))->assertRedirect(route('login'));
        $this->patch(route('dehusking.reverse', $order))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_can_access_the_production_screens(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('dehusking.index'))->assertOk();
        $this->actingAs($user)->get(route('dehusking.create'))->assertOk();
    }

    #[Test]
    public function the_create_screen_exposes_the_recipe_so_outputs_can_be_previewed(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('10000.000', '102.500000');

        $this->actingAs($user)
            ->get(route('dehusking.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/dehusking/Create')
                // One recipe per coconut size, the original coconut first.
                ->has('recipes', 4)
                ->where('recipes.0.input_code', 'COCONUT')
                ->where('recipes.1.input_code', 'COCONUT-LARGE')
                ->where('recipes.1.outputs.0.code', 'DEHUSKED-LARGE')
                ->where('recipes.1.outputs.1.code', 'RAW-HUSK')
                ->has('recipes.0.outputs', 2)
                ->where('recipes.0.outputs.0.code', 'DEHUSKED-COCONUT')
                ->where('recipes.0.outputs.0.ratio', '1.000000')
                ->where('recipes.0.outputs.0.allocation_percent', '100.000000')
                ->where('recipes.0.outputs.0.absorbs_labour', false)
                ->where('recipes.0.outputs.1.code', 'RAW-HUSK')
                ->where('recipes.0.outputs.1.allocation_percent', '0.000000')
                // The raw husk carries the dehusking labour instead of coconut value.
                ->where('recipes.0.outputs.1.absorbs_labour', true)
                ->where('recipes.0.default_labour_rate', '4.000000')
                ->where('recipes.0.input_tracks_lots', true)
                // Only lots holding stock are offered.
                ->has('recipes.0.lots', 1));
    }

    #[Test]
    public function a_draft_created_through_the_screen_moves_no_stock(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('10000.000', '102.500000');

        $this->actingAs($user)->post(route('dehusking.store'), [
            'production_date' => now()->toDateString(),
            'warehouse_id' => $this->main->id,
            'lot_id' => $this->lot->id,
            'quantity' => '1000',
            'labour_rate' => '2.50',
        ])->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();

        $this->assertMatchesRegularExpression('/^PRD-\d{8}-\d{6}$/', $order->production_number);
        $this->assertSame(ProductionStatus::Draft, $order->status);

        // The input product came from the recipe, not the request.
        $this->assertSame($this->coconut->id, $order->inputs()->first()->product_id);

        // Only the purchase receipt exists.
        $this->assertSame(1, InventoryTransaction::count());
        $this->assertSame('10000.000', app(StockQueryService::class)->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function the_screen_never_accepts_output_quantities_or_costs(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('10000.000', '102.500000');

        // Attempt to smuggle in output quantities and costs — all must be ignored.
        $this->actingAs($user)->post(route('dehusking.store'), [
            'production_date' => now()->toDateString(),
            'warehouse_id' => $this->main->id,
            'lot_id' => $this->lot->id,
            'quantity' => '1000',
            'labour_rate' => '2.50',
            'outputs' => [
                ['product_id' => $this->dehusked->id, 'quantity' => '99999', 'allocated_cost' => '1'],
            ],
            'unit_cost' => '0.000001',
            'total_cost' => '1.000000',
        ])->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();

        // No outputs exist on a draft at all, and the input carries no cost yet.
        $this->assertSame(0, $order->outputs()->count());
        $this->assertNull($order->inputs()->first()->unit_cost);
        $this->assertNull($order->inputs()->first()->total_cost);

        $this->actingAs($user)->patch(route('dehusking.post', $order));

        // After posting, quantities follow the 1:1 recipe — not the 99999 that was sent.
        $posted = $order->fresh(['outputs']);
        foreach ($posted->outputs as $output) {
            $this->assertSame('1000.000', $output->quantity);
        }
    }

    #[Test]
    public function posting_through_the_screen_converts_stock_and_conserves_value(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('10000.000', '102.500000');

        $order = ProductionOrder::factory()->create(['warehouse_id' => $this->main->id, 'labour_rate' => '2.500000']);
        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->coconut->id,
            'lot_id' => $this->lot->id,
            'quantity' => '1000.000',
        ]);

        $this->actingAs($user)
            ->patch(route('dehusking.post', $order))
            ->assertSessionHasNoErrors();

        $stock = app(StockQueryService::class);

        $this->assertSame('9000.000', $stock->quantityForProduct($this->coconut->id));
        $this->assertSame('1000.000', $stock->quantityForProduct($this->dehusked->id));
        $this->assertSame('1000.000', $stock->quantityForProduct($this->husk->id));

        $posted = $order->fresh(['inputs', 'outputs']);
        $this->assertSame(ProductionStatus::Posted, $posted->status);
        $this->assertSame($user->id, $posted->posted_by);
        // Output value = coconut value + 1,000 x 2.50 of labour.
        $this->assertSame('102500.000000', $posted->inputValue());
        $this->assertSame('2500.000000', $posted->labour_cost);
        $this->assertSame('105000.000000', $posted->outputValue());
    }

    #[Test]
    public function the_show_screen_hides_costs_until_the_run_is_posted(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('10000.000', '102.500000');

        $order = ProductionOrder::factory()->create(['warehouse_id' => $this->main->id, 'labour_rate' => '2.500000']);
        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->coconut->id,
            'lot_id' => $this->lot->id,
            'quantity' => '1000.000',
        ]);

        $this->actingAs($user)
            ->get(route('dehusking.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/dehusking/Show')
                ->where('order.status', 'DRAFT')
                ->where('order.can_post', true)
                ->where('order.input_value', null)
                ->where('order.output_value', null)
                ->has('order.outputs', 0));

        $this->actingAs($user)->patch(route('dehusking.post', $order));

        $this->actingAs($user)
            ->get(route('dehusking.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.status', 'POSTED')
                ->where('order.input_value', '102500.000000')
                ->where('order.output_value', '105000.000000')
                ->where('order.input.unit_cost', '102.500000')
                ->where('order.labour_rate', '2.500000')
                ->where('order.labour_cost', '2500.000000')
                ->has('order.outputs', 2)
                ->where('order.outputs.0.allocated_cost', '102500.000000')
                ->where('order.outputs.0.unit_cost', '102.500000')
                ->where('order.outputs.0.labour_cost', null)
                ->where('order.outputs.1.allocated_cost', '2500.000000')
                ->where('order.outputs.1.labour_cost', '2500.000000')
                ->where('order.outputs.1.unit_cost', '2.500000'));
    }

    #[Test]
    public function a_posted_run_cannot_be_edited_through_the_screen(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('10000.000', '102.500000');

        $order = ProductionOrder::factory()->create(['warehouse_id' => $this->main->id, 'labour_rate' => '2.500000']);
        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->coconut->id,
            'lot_id' => $this->lot->id,
            'quantity' => '1000.000',
        ]);

        $this->actingAs($user)->patch(route('dehusking.post', $order));

        $this->actingAs($user)
            ->get(route('dehusking.edit', $order))
            ->assertSessionHasErrors('status');

        $this->actingAs($user)->put(route('dehusking.update', $order), [
            'production_date' => now()->toDateString(),
            'warehouse_id' => $this->main->id,
            'lot_id' => $this->lot->id,
            'quantity' => '5',
            'labour_rate' => '2.50',
        ])->assertSessionHasErrors('status');

        $this->assertSame('1000.000', $order->fresh()->inputs()->first()->quantity);
    }

    #[Test]
    public function the_screen_requires_a_non_negative_labour_rate(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('10000.000', '102.500000');

        $payload = [
            'production_date' => now()->toDateString(),
            'warehouse_id' => $this->main->id,
            'lot_id' => $this->lot->id,
            'quantity' => '1000',
        ];

        $this->actingAs($user)->post(route('dehusking.store'), $payload)
            ->assertSessionHasErrors('labour_rate');

        $this->actingAs($user)->post(route('dehusking.store'), $payload + ['labour_rate' => '-1'])
            ->assertSessionHasErrors('labour_rate');

        $this->assertSame(0, ProductionOrder::count());

        // Zero is a deliberate answer, not a missing one.
        $this->actingAs($user)->post(route('dehusking.store'), $payload + ['labour_rate' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame('0.000000', ProductionOrder::firstOrFail()->labour_rate);
    }

    #[Test]
    public function pooled_coconut_is_entered_without_a_lot_and_a_lot_is_refused(): void
    {
        $user = User::factory()->admin()->create();
        $this->coconut->update(['tracks_lots' => false]);

        $payload = [
            'production_date' => now()->toDateString(),
            'warehouse_id' => $this->main->id,
            'quantity' => '100',
            'labour_rate' => '2',
        ];

        // A lot for pooled stock is refused rather than silently ignored.
        $this->actingAs($user)->post(route('dehusking.store'), $payload + ['lot_id' => $this->lot->id])
            ->assertSessionHasErrors('lot_id');

        $this->assertSame(0, ProductionOrder::count());

        $this->actingAs($user)->post(route('dehusking.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertNull(ProductionOrder::firstOrFail()->inputs()->first()->lot_id);

        // The create screen offers no lots for pooled coconut.
        $this->actingAs($user)
            ->get(route('dehusking.create'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('recipes.0.input_tracks_lots', false)
                ->has('recipes.0.lots', 0));
    }

    #[Test]
    public function production_registers_no_destroy_routes(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): ?string => $route->getName())
            ->filter()
            ->filter(fn (string $name): bool => str_starts_with($name, 'dehusking.'));

        $this->assertTrue($names->isNotEmpty());
        $this->assertCount(
            0,
            $names->filter(fn (string $name): bool => str_contains($name, 'destroy')),
            'Production must expose no destroy route.'
        );
    }
}
