<?php

namespace Tests\Feature\Production;

use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionStatus;
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
 * The husk chipping screens: flexible output rows, no raw husk input, and outputs that enter
 * stock with their raw husk cost pending backflush.
 */
class HuskChippingScreensTest extends TestCase
{
    use RefreshDatabase;

    private Product $husk;

    private Product $c7;

    private Product $c9;

    private Product $c10;

    private UnitOfMeasure $kg;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->husk = Product::factory()->create(['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary_uom_id' => $nos->id]);
        $this->c7 = Product::factory()->create(['code' => 'HUSK-7C', 'name' => '7C Husk Chips', 'primary_uom_id' => $this->kg->id]);
        $this->c9 = Product::factory()->create(['code' => 'HUSK-9C', 'name' => '9C Husk Chips', 'primary_uom_id' => $this->kg->id]);
        $this->c10 = Product::factory()->create(['code' => 'HUSK-10C', 'name' => '10C Husk Chips', 'primary_uom_id' => $this->kg->id]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->seedProductionRecipes();
    }

    private function product(string $code): Product
    {
        return Product::query()->where('code', $code)->firstOrFail();
    }

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
     * @param  list<array<string, mixed>>|null  $outputs
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function payload(?array $outputs = null, array $extra = []): array
    {
        return array_merge([
            'production_date' => now()->toDateString(),
            'warehouse_id' => $this->main->id,
            'outputs' => $outputs ?? [
                ['product_id' => $this->c7->id, 'quantity' => '150.000'],
                ['product_id' => $this->c9->id, 'quantity' => '200.000'],
                ['product_id' => $this->c10->id, 'quantity' => '280.000'],
            ],
        ], $extra);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
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
    public function a_user_without_the_production_permissions_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('husk-chipping.index'))->assertForbidden();
        $this->actingAs($user)->get(route('husk-chipping.create'))->assertForbidden();
        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload())->assertForbidden();

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function an_authorised_user_can_access_the_husk_chipping_screens(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->get(route('husk-chipping.index'))->assertOk();
        $this->actingAs($user)->get(route('husk-chipping.create'))->assertOk();
    }

    #[Test]
    public function the_create_screen_offers_every_output_in_its_own_unit_and_no_husk_lot(): void
    {
        $this->receiveHusk('6000', '19.500000');

        $this->actingAs($this->admin())
            ->get(route('husk-chipping.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/husk-chipping/Create')
                ->where('recipe.input_code', 'RAW-HUSK')
                ->where('recipe.input_uom', 'NOS')
                ->has('recipe.outputs', 8)
                ->where('recipe.outputs.0.product_id', $this->c7->id)
                ->where('recipe.outputs.0.code', 'HUSK-7C')
                ->where('recipe.outputs.0.uom', 'KG')
                ->where('recipe.outputs.0.decimals', 3)
                ->where('recipe.outputs.3.code', 'COCOPEAT')
                ->where('recipe.outputs.7.code', 'COCOPEAT-L')
                ->where('recipe.outputs.7.uom', 'L')
                // The raw husk pool is shown for information only.
                ->where('inputStock', '6000.000')
                // Waste units come from the UOM master, not from an invented unit.
                ->has('wasteUoms', 3)
                ->missing('lots'));
    }

    #[Test]
    public function a_draft_created_through_the_screen_has_no_input_and_moves_no_stock(): void
    {
        $this->receiveHusk('6000', '19.500000');

        $this->actingAs($this->admin())
            ->post(route('husk-chipping.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();

        $this->assertMatchesRegularExpression('/^PRD-\d{8}-\d{6}$/', $order->production_number);
        $this->assertSame(ProductionStatus::Draft, $order->status);
        $this->assertSame(0, $order->inputs()->count());
        $this->assertSame(
            ['150.000', '200.000', '280.000'],
            $order->outputs()->orderBy('id')->pluck('quantity')->all(),
        );

        // Only the purchase receipt exists.
        $this->assertSame(1, InventoryTransaction::count());
    }

    #[Test]
    public function a_husk_lot_or_husk_quantity_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('husk-chipping.store'), $this->payload(extra: ['lot_id' => 1, 'quantity' => '2000']))
            ->assertSessionHasErrors(['lot_id', 'quantity']);

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function a_run_may_record_any_subset_of_outputs_in_kilograms_or_litres(): void
    {
        $this->actingAs($this->admin())->post(route('husk-chipping.store'), $this->payload([
            ['product_id' => $this->c7->id, 'quantity' => '40.000'],
            ['product_id' => $this->product('COCOPEAT-L')->id, 'quantity' => '100.000'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, ProductionOrder::firstOrFail()->outputs()->count());
    }

    #[Test]
    public function at_least_one_output_row_is_required(): void
    {
        $this->actingAs($this->admin())
            ->post(route('husk-chipping.store'), $this->payload([]))
            ->assertSessionHasErrors('outputs');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function a_zero_or_negative_row_is_refused(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload([
            ['product_id' => $this->c7->id, 'quantity' => '40.000'],
            ['product_id' => $this->c9->id, 'quantity' => '0'],
        ]))->assertSessionHasErrors('outputs.1.quantity');

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload([
            ['product_id' => $this->c7->id, 'quantity' => '-1.000'],
        ]))->assertSessionHasErrors('outputs.0.quantity');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function a_product_may_appear_only_once(): void
    {
        $this->actingAs($this->admin())->post(route('husk-chipping.store'), $this->payload([
            ['product_id' => $this->c7->id, 'quantity' => '40.000'],
            ['product_id' => $this->c7->id, 'quantity' => '10.000'],
        ]))->assertSessionHasErrors('outputs.1.product_id');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function a_product_outside_the_recipe_is_refused_by_the_screen(): void
    {
        $this->actingAs($this->admin())->post(route('husk-chipping.store'), $this->payload([
            ['product_id' => $this->c7->id, 'quantity' => '150.000'],
            ['product_id' => $this->husk->id, 'quantity' => '280.000'],
        ]))->assertSessionHasErrors('outputs.1.product_id');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function a_quantity_is_checked_against_its_own_units_precision(): void
    {
        $this->actingAs($this->admin())->post(route('husk-chipping.store'), $this->payload([
            ['product_id' => $this->c7->id, 'quantity' => '1.2345'],
        ]))->assertSessionHasErrors('outputs.0.quantity');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function the_screen_never_accepts_a_cost(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload([
            ['product_id' => $this->c7->id, 'quantity' => '150.000', 'allocated_cost' => '999999.000000'],
            ['product_id' => $this->c9->id, 'quantity' => '200.000', 'unit_cost' => '1.000000'],
        ], ['unit_cost' => '0.000001', 'total_cost' => '1.000000']))->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();

        foreach ($order->outputs as $output) {
            $this->assertNull($output->allocated_cost);
            $this->assertNull($output->unit_cost);
        }

        $this->actingAs($user)->patch(route('husk-chipping.post', $order))->assertSessionHasNoErrors();

        // The outputs carry no value until the delivery backflushes the raw husk.
        $this->assertSame('0.000000', $order->fresh(['outputs'])->outputValue());
    }

    #[Test]
    public function waste_is_optional_but_needs_a_unit_when_given(): void
    {
        $user = $this->admin();

        $this->actingAs($user)
            ->post(route('husk-chipping.store'), $this->payload(extra: ['waste_quantity' => '42.500']))
            ->assertSessionHasErrors('waste_uom_id');

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload(extra: [
            'waste_quantity' => '42.500',
            'waste_uom_id' => $this->kg->id,
        ]))->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();
        $this->assertSame('42.500', $order->waste_quantity);
        $this->assertSame($this->kg->id, $order->waste_uom_id);
        $this->assertSame(0, InventoryTransaction::count());
    }

    #[Test]
    public function posting_through_the_screen_adds_the_outputs_and_takes_no_raw_husk(): void
    {
        $user = $this->admin();
        $this->receiveHusk('6000', '20.000000');

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload());
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)
            ->patch(route('husk-chipping.post', $order))
            ->assertSessionHasNoErrors();

        $stock = app(StockQueryService::class);

        $this->assertSame('6000.000', $stock->quantityForProduct($this->husk->id));
        $this->assertSame('120000.000000', $stock->valueForProduct($this->husk->id));
        $this->assertSame('150.000', $stock->quantityForProduct($this->c7->id));
        $this->assertSame('200.000', $stock->quantityForProduct($this->c9->id));
        $this->assertSame('280.000', $stock->quantityForProduct($this->c10->id));

        $posted = $order->fresh();
        $this->assertSame(ProductionStatus::Posted, $posted->status);
        $this->assertSame($user->id, $posted->posted_by);
    }

    #[Test]
    public function the_show_screen_marks_the_raw_husk_cost_as_pending_backflush(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload());
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)
            ->get(route('husk-chipping.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/husk-chipping/Show')
                ->where('order.status', 'DRAFT')
                ->where('order.can_post', true)
                ->where('order.input', null)
                ->where('order.pending_backflush', true)
                ->where('order.output_value', null)
                ->where('order.output_quantity', '630.000 KG')
                ->has('order.outputs', 3)
                ->where('order.outputs.0.allocated_cost', null)
                ->where('order.outputs.0.lot_number', null));

        $this->actingAs($user)->patch(route('husk-chipping.post', $order));

        $this->actingAs($user)
            ->get(route('husk-chipping.show', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('order.status', 'POSTED')
                ->where('order.pending_backflush', true)
                ->where('order.consumption_reference', null)
                ->where('order.output_value', '0.000000')
                ->where('order.outputs.0.allocated_cost', '0.000000')
                ->where('order.outputs.0.unit_cost', '0.000000')
                // The factory-made grade tracks lots, so it got one.
                ->whereNot('order.outputs.0.lot_number', null));
    }

    #[Test]
    public function output_totals_are_shown_per_unit_never_across_units(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload([
            ['product_id' => $this->c7->id, 'quantity' => '40.000'],
            ['product_id' => $this->product('COCOPEAT-L')->id, 'quantity' => '100.000'],
        ]));

        $this->actingAs($user)
            ->get(route('husk-chipping.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('orders.data.0.output_quantity', '40.000 KG · 100.000 L'));
    }

    #[Test]
    public function a_draft_can_be_reweighed_through_the_screen(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload());
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)
            ->get(route('husk-chipping.edit', $order))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/husk-chipping/Edit')
                ->has('order.outputs', 3)
                ->where('order.outputs.0.quantity', '150.000')
                ->missing('order.lot_id')
                ->missing('order.quantity')
                ->missing('lots'));

        $this->actingAs($user)->put(route('husk-chipping.update', $order), $this->payload([
            ['product_id' => $this->c7->id, 'quantity' => '111.000'],
            ['product_id' => $this->product('COCOPEAT')->id, 'quantity' => '33.000'],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(
            ['111.000', '33.000'],
            $order->fresh()->outputs()->orderBy('id')->pluck('quantity')->all(),
        );
        $this->assertSame(0, InventoryTransaction::count());
    }

    #[Test]
    public function a_posted_run_cannot_be_edited_through_the_screen(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload());
        $order = ProductionOrder::firstOrFail();

        $this->actingAs($user)->patch(route('husk-chipping.post', $order));

        $this->actingAs($user)
            ->get(route('husk-chipping.edit', $order))
            ->assertSessionHasErrors('status');

        $this->actingAs($user)
            ->put(route('husk-chipping.update', $order), $this->payload([
                ['product_id' => $this->c7->id, 'quantity' => '1.000'],
            ]))
            ->assertSessionHasErrors('status');

        $this->assertSame('150.000', $order->fresh()->outputs()->orderBy('id')->first()->quantity);
    }

    #[Test]
    public function a_posted_run_can_be_reversed_through_the_screen(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload());
        $order = ProductionOrder::firstOrFail();
        $this->actingAs($user)->patch(route('husk-chipping.post', $order));

        $this->actingAs($user)
            ->patch(route('husk-chipping.reverse', $order))
            ->assertSessionHasNoErrors();

        $this->assertSame(ProductionStatus::Reversed, $order->fresh()->status);
        $this->assertSame('0.000', app(StockQueryService::class)->quantityForProduct($this->c7->id));
    }

    #[Test]
    public function the_index_screen_lists_only_husk_chipping_runs(): void
    {
        $user = $this->admin();

        $this->actingAs($user)->post(route('husk-chipping.store'), $this->payload());

        // A dehusking order must not appear here.
        ProductionOrder::factory()->create(['warehouse_id' => $this->main->id]);

        $this->actingAs($user)
            ->get(route('husk-chipping.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('production/husk-chipping/Index')
                ->has('orders.data', 1)
                // No raw husk is taken by a chipping run.
                ->where('orders.data.0.input_quantity', '0.000')
                ->where('orders.data.0.output_quantity', '630.000 KG'));
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
