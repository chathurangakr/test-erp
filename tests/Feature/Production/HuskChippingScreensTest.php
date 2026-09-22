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
use Database\Seeders\ProductionRecipeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HuskChippingScreensTest extends TestCase
{
    use RefreshDatabase;

    private Product $husk;

    private Product $c7;

    private Product $c9;

    private Product $c10;

    private UnitOfMeasure $kg;

    private Warehouse $main;

    private InventoryLot $lot;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => $nos->id]);
        Product::factory()->create(['code' => 'DEHUSKED-COCONUT', 'primary_uom_id' => $nos->id]);
        Product::factory()->create(['code' => 'COPRA', 'primary_uom_id' => $this->kg->id]);
        Product::factory()->create([
            'code' => 'RAW-SHELL',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => $this->kg->id,
        ]);

        $this->husk = Product::factory()->create(['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary_uom_id' => $nos->id]);
        $this->c7 = Product::factory()->create(['code' => 'HUSK-7C', 'name' => '7C Husk Chips', 'primary_uom_id' => $this->kg->id]);
        $this->c9 = Product::factory()->create(['code' => 'HUSK-9C', 'name' => '9C Husk Chips', 'primary_uom_id' => $this->kg->id]);
        $this->c10 = Product::factory()->create(['code' => 'HUSK-10C', 'name' => '10C Husk Chips', 'primary_uom_id' => $this->kg->id]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->seed(ProductionRecipeSeeder::class);

        $this->lot = app(CreateInventoryLot::class)->handle($this->husk);
    }

    private function receive(string $quantity, string $unitCost): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->husk->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $this->lot->id,
                unitCost: $unitCost,
            ),
        ]);
    }

    /**
     * @param  array<string, string>  $weights  7C, 9C, 10C
     * @return array<string, mixed>
     */
    private function payload(array $weights = ['150.000', '200.000', '280.000'], array $extra = []): array
    {
        return array_merge([
            'production_date' => now()->toDateString(),
            'warehouse_id' => $this->main->id,
            'lot_id' => $this->lot->id,
            'quantity' => '2000',
            'outputs' => [
                ['product_id' => $this->c7->id, 'quantity' => $weights[0]],
                ['product_id' => $this->c9->id, 'quantity' => $weights[1]],
                ['product_id' => $this->c10->id, 'quantity' => $weights[2]],
            ],
        ], $extra);
    }

    #[Test]
    public function a_guest_cannot_access_the_husk_chipping_screens(): void
    {
        $order = ProductionOrder::factory()->huskChipping()->create(['warehouse_id' => $this->main->id]);

        $this->get(route('husk-chipping.index'))->assertRedirect(route('login'));
        $this->get(route('husk-chipping.create'))->assertRedirect(route('login'));
        $this->get(route('husk-chipping.show', $order))->assertRedirect(route('login'));
        $this->post(route('husk-chipping.store'), [])->assertRedirect(route('login'));
        $this->patch(route('husk-chipping.post', $order))->assertRedirect(route('login'));
        $this->patch(route('husk-chipping.reverse', $order))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_can_access_the_husk_chipping_screens(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('husk-chipping.index'))->assertOk();
        $this->actingAs($user)->get(route('husk-chipping.create'))->assertOk();
    }

    #[Test]
    public function the_create_screen_offers_the_grades_to_weigh_and_no_conversion(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '19.500000');

        $this->actingAs($user)
            ->get(route('husk-chipping.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/husk-chipping/Create')
                ->where('recipe.input_code', 'RAW-HUSK')
                ->where('recipe.input_uom', 'NOS')
                ->has('recipe.outputs', 3)
                ->where('recipe.outputs.0.code', 'HUSK-7C')
                ->where('recipe.outputs.0.uom', 'KG')
                // Null ratio: there is nothing to derive a weight from.
                ->where('recipe.outputs.0.ratio', null)
                ->where('recipe.outputs.0.allocation_percent', '30.000000')
                ->where('recipe.outputs.1.ratio', null)
                ->where('recipe.outputs.1.allocation_percent', '30.000000')
                ->where('recipe.outputs.2.code', 'HUSK-10C')
                ->where('recipe.outputs.2.ratio', null)
                ->where('recipe.outputs.2.allocation_percent', '40.000000')
                // Waste units come from the UOM master, not from an invented unit.
                ->has('wasteUoms', 2)
                ->has('lots', 1));
    }

    #[Test]
    public function a_draft_created_through_the_screen_moves_no_stock(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '19.500000');

        $this->actingAs($user)
            ->post(route('husk-chipping.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();

        $this->assertMatchesRegularExpression('/^PRD-\d{8}-\d{6}$/', $order->production_number);
        $this->assertSame(ProductionStatus::Draft, $order->status);

        // The input product came from the recipe, not the request.
        $this->assertSame($this->husk->id, $order->inputs()->first()->product_id);

        // The measured weights were stored exactly as typed.
        $this->assertSame(
            ['150.000', '200.000', '280.000'],
            $order->outputs()->orderBy('id')->pluck('quantity')->all(),
        );

        // Only the purchase receipt exists.
        $this->assertSame(1, InventoryTransaction::count());
        $this->assertSame('6000.000', app(StockQueryService::class)->quantityForProduct($this->husk->id));
    }

    #[Test]
    public function the_screen_never_accepts_a_cost(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '19.500000');

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload(extra: [
            'unit_cost' => '0.000001',
            'total_cost' => '1.000000',
            'outputs' => [
                ['product_id' => $this->c7->id, 'quantity' => '150.000', 'allocated_cost' => '999999.000000'],
                ['product_id' => $this->c9->id, 'quantity' => '200.000', 'unit_cost' => '1.000000'],
                ['product_id' => $this->c10->id, 'quantity' => '280.000'],
            ],
        ]))->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();

        foreach ($order->outputs as $output) {
            $this->assertNull($output->allocated_cost);
            $this->assertNull($output->unit_cost);
        }

        $this->assertNull($order->inputs()->first()->unit_cost);
        $this->assertNull($order->inputs()->first()->total_cost);

        $this->actingAs($user)->patch(route('husk-chipping.post', $order));

        // Costs came from the ledger, not from the request.
        $posted = $order->fresh(['inputs', 'outputs']);
        $this->assertSame('39000.000000', $posted->inputValue());
        $this->assertSame('39000.000000', $posted->outputValue());
    }

    #[Test]
    public function a_grade_may_be_zero_but_not_every_grade(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '19.500000');

        $this->actingAs($user)
            ->post(route('husk-chipping.store'), $this->payload(['100.000', '0', '200.000']))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('husk-chipping.store'), $this->payload(['0', '0', '0']))
            ->assertSessionHasErrors('outputs');

        $this->assertSame(1, ProductionOrder::count());
    }

    #[Test]
    public function a_negative_weight_is_refused_by_the_screen(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '19.500000');

        $this->actingAs($user)
            ->post(route('husk-chipping.store'), $this->payload(['-1.000', '200.000', '280.000']))
            ->assertSessionHasErrors('outputs.0.quantity');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function every_grade_must_be_accounted_for(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '19.500000');

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload(extra: [
            'outputs' => [
                ['product_id' => $this->c7->id, 'quantity' => '150.000'],
                ['product_id' => $this->c9->id, 'quantity' => '200.000'],
            ],
        ]))->assertSessionHasErrors('outputs');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function a_product_outside_the_recipe_is_refused_by_the_screen(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '19.500000');

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload(extra: [
            'outputs' => [
                ['product_id' => $this->c7->id, 'quantity' => '150.000'],
                ['product_id' => $this->c9->id, 'quantity' => '200.000'],
                ['product_id' => $this->husk->id, 'quantity' => '280.000'],
            ],
        ]))->assertSessionHasErrors('outputs.2.product_id');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function waste_is_optional_but_needs_a_unit_when_given(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '19.500000');

        // Without a unit.
        $this->actingAs($user)
            ->post(route('husk-chipping.store'), $this->payload(extra: ['waste_quantity' => '42.500']))
            ->assertSessionHasErrors('waste_uom_id');

        // With one.
        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload(extra: [
            'waste_quantity' => '42.500',
            'waste_uom_id' => $this->kg->id,
        ]))->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();
        $this->assertSame('42.500', $order->waste_quantity);
        $this->assertSame($this->kg->id, $order->waste_uom_id);

        // And it created nothing in inventory.
        $this->assertSame(1, InventoryTransaction::count());
        $this->assertSame(1, InventoryLot::count());
    }

    #[Test]
    public function posting_through_the_screen_converts_stock_and_conserves_value(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '20.000000');

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload());
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)
            ->patch(route('husk-chipping.post', $order))
            ->assertSessionHasNoErrors();

        $stock = app(StockQueryService::class);

        $this->assertSame('4000.000', $stock->quantityForProduct($this->husk->id));
        $this->assertSame('150.000', $stock->quantityForProduct($this->c7->id));
        $this->assertSame('200.000', $stock->quantityForProduct($this->c9->id));
        $this->assertSame('280.000', $stock->quantityForProduct($this->c10->id));

        $posted = $order->fresh(['inputs', 'outputs']);
        $this->assertSame(ProductionStatus::Posted, $posted->status);
        $this->assertSame($user->id, $posted->posted_by);
        $this->assertSame('40000.000000', $posted->inputValue());
        $this->assertSame('40000.000000', $posted->outputValue());
    }

    #[Test]
    public function the_show_screen_hides_costs_until_the_run_is_posted(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '20.000000');

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload());
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)
            ->get(route('husk-chipping.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/husk-chipping/Show')
                ->where('order.status', 'DRAFT')
                ->where('order.can_post', true)
                ->where('order.input_value', null)
                ->where('order.output_value', null)
                ->where('order.output_quantity', '630.000')
                ->has('order.outputs', 3)
                ->where('order.outputs.0.allocated_cost', null)
                ->where('order.outputs.0.lot_number', null));

        $this->actingAs($user)->patch(route('husk-chipping.post', $order));

        $this->actingAs($user)
            ->get(route('husk-chipping.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.status', 'POSTED')
                ->where('order.input_value', '40000.000000')
                ->where('order.output_value', '40000.000000')
                ->where('order.input.unit_cost', '20.000000')
                ->where('order.outputs.0.allocated_cost', '12000.000000')
                ->where('order.outputs.0.unit_cost', '80.000000')
                ->where('order.outputs.1.allocated_cost', '12000.000000')
                ->where('order.outputs.1.unit_cost', '60.000000')
                ->where('order.outputs.2.allocated_cost', '16000.000000')
                ->where('order.outputs.2.unit_cost', '57.142857'));
    }

    #[Test]
    public function a_draft_can_be_reweighed_through_the_screen(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '20.000000');

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload());
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)
            ->get(route('husk-chipping.edit', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/husk-chipping/Edit')
                ->has('order.outputs', 3)
                ->where('order.outputs.0.quantity', '150.000'));

        $this->actingAs($user)->put(route('husk-chipping.update', $order), $this->payload([
            '111.000', '0', '333.000',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(
            ['111.000', '0.000', '333.000'],
            $order->fresh()->outputs()->orderBy('id')->pluck('quantity')->all(),
        );

        // Still a draft — nothing moved.
        $this->assertSame(1, InventoryTransaction::count());
    }

    #[Test]
    public function a_posted_run_cannot_be_edited_through_the_screen(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '20.000000');

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload());
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)->patch(route('husk-chipping.post', $order));

        $this->actingAs($user)
            ->get(route('husk-chipping.edit', $order))
            ->assertSessionHasErrors('status');

        $this->actingAs($user)
            ->put(route('husk-chipping.update', $order), $this->payload(['1.000', '1.000', '1.000']))
            ->assertSessionHasErrors('status');

        $this->assertSame('150.000', $order->fresh()->outputs()->orderBy('id')->first()->quantity);
    }

    #[Test]
    public function the_index_screen_lists_only_husk_chipping_runs(): void
    {
        $user = User::factory()->create();
        $this->receive('6000.000', '20.000000');

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload());

        // A dehusking order must not appear here.
        ProductionOrder::factory()->create(['warehouse_id' => $this->main->id]);

        $this->actingAs($user)
            ->get(route('husk-chipping.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/husk-chipping/Index')
                ->has('orders.data', 1)
                ->where('orders.data.0.input_quantity', '2000.000')
                ->where('orders.data.0.output_quantity', '630.000'));
    }

    #[Test]
    public function husk_chipping_registers_no_destroy_routes(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): ?string => $route->getName())
            ->filter()
            ->filter(fn (string $name): bool => str_starts_with($name, 'husk-chipping.'));

        $this->assertTrue($names->isNotEmpty());
        $this->assertCount(
            0,
            $names->filter(fn (string $name): bool => str_contains($name, 'destroy')),
            'Husk chipping must expose no destroy route.'
        );
    }
}
