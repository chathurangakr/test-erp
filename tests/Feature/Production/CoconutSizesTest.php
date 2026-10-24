<?php

namespace Tests\Feature\Production;

use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Production\SaveDehuskingOrder;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionStatus;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Coconuts are bought and kept by size (2026-10-24): each size is its own stock, dehusked into
 * dehusked coconut of the same size, while husk, copra and shell stay one stock each.
 */
class CoconutSizesTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $main;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->seedProductionRecipes();
        $this->user = User::factory()->admin()->create();

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine($this->product('COCONUT')->id, $this->main->id, InventoryDirection::In, '1000.000', unitCost: '80.000000'),
            new PendingInventoryLine($this->product('COCONUT-LARGE')->id, $this->main->id, InventoryDirection::In, '500.000', unitCost: '120.000000'),
            new PendingInventoryLine($this->product('DEHUSKED-SMALL')->id, $this->main->id, InventoryDirection::In, '300.000', unitCost: '70.000000'),
        ]);
    }

    private function product(string $code): Product
    {
        return Product::where('code', $code)->firstOrFail();
    }

    private function quantity(string $code): string
    {
        return app(StockQueryService::class)->quantityForProduct($this->product($code)->id);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function dehusk(string $quantity, array $extra = []): ProductionOrder
    {
        $this->actingAs($this->user)->post(route('dehusking.store'), [
            'production_date' => '2026-09-20',
            'warehouse_id' => $this->main->id,
            'quantity' => $quantity,
            'labour_rate' => '4.00',
        ] + $extra)->assertSessionHasNoErrors();

        $order = ProductionOrder::latest('id')->firstOrFail();
        $this->actingAs($this->user)->patch(route('dehusking.post', $order))->assertSessionHasNoErrors();

        return $order->refresh();
    }

    #[Test]
    public function a_size_is_dehusked_into_dehusked_coconut_of_the_same_size_and_the_one_husk_stock(): void
    {
        $order = $this->dehusk('200', ['input_product_id' => $this->product('COCONUT-LARGE')->id]);

        $this->assertSame(ProductionStatus::Posted, $order->status);
        $this->assertSame('300.000', $this->quantity('COCONUT-LARGE'));
        $this->assertSame('200.000', $this->quantity('DEHUSKED-LARGE'));
        $this->assertSame('200.000', $this->quantity('RAW-HUSK'));
        // The plain coconut and the other sizes are untouched.
        $this->assertSame('1000.000', $this->quantity('COCONUT'));
        $this->assertSame('0.000', $this->quantity('DEHUSKED-COCONUT'));

        // The large coconut's own cost goes with it: 200 x Rs. 120.
        $this->assertSame('24000.000000', app(StockQueryService::class)->valueForProduct($this->product('DEHUSKED-LARGE')->id));
    }

    #[Test]
    public function naming_no_size_dehusks_the_original_coconut_as_before(): void
    {
        $this->dehusk('100');

        $this->assertSame('900.000', $this->quantity('COCONUT'));
        $this->assertSame('100.000', $this->quantity('DEHUSKED-COCONUT'));
        $this->assertSame('500.000', $this->quantity('COCONUT-LARGE'));
    }

    #[Test]
    public function only_a_product_with_a_recipe_can_be_dehusked(): void
    {
        $this->actingAs($this->user)->post(route('dehusking.store'), [
            'production_date' => '2026-09-20',
            'warehouse_id' => $this->main->id,
            'input_product_id' => $this->product('COPRA')->id,
            'quantity' => '10',
            'labour_rate' => '4.00',
        ])->assertSessionHasErrors('input_product_id');

        $this->assertSame(0, ProductionOrder::count());
    }

    #[Test]
    public function every_dehusked_size_dries_into_the_one_copra_and_shell_stock(): void
    {
        $this->actingAs($this->user)->post(route('copra.store'), [
            'production_date' => '2026-09-20',
            'warehouse_id' => $this->main->id,
            'input_product_id' => $this->product('DEHUSKED-SMALL')->id,
            'quantity' => '300',
            'outputs' => [
                ['product_id' => $this->product('COPRA')->id, 'quantity' => '45.5'],
                ['product_id' => $this->product('RAW-SHELL')->id],
            ],
        ])->assertSessionHasNoErrors();

        $order = ProductionOrder::latest('id')->firstOrFail();
        $this->actingAs($this->user)->patch(route('copra.post', $order))->assertSessionHasNoErrors();

        $this->assertSame('0.000', $this->quantity('DEHUSKED-SMALL'));
        $this->assertSame('45.500', $this->quantity('COPRA'));
        $this->assertSame('300.000', $this->quantity('RAW-SHELL'));
        // The copra carries the whole value; the shell comes free.
        $this->assertSame('21000.000000', app(StockQueryService::class)->valueForProduct($this->product('COPRA')->id));
    }

    #[Test]
    public function editing_a_draft_without_naming_a_size_keeps_its_size(): void
    {
        $this->actingAs($this->user)->post(route('dehusking.store'), [
            'production_date' => '2026-09-20',
            'warehouse_id' => $this->main->id,
            'input_product_id' => $this->product('COCONUT-LARGE')->id,
            'quantity' => '10',
            'labour_rate' => '4.00',
        ]);
        $order = ProductionOrder::latest('id')->firstOrFail();

        $this->actingAs($this->user)->get(route('dehusking.edit', $order))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('order.input_product_id', $this->product('COCONUT-LARGE')->id));

        // An edit that names no size — an older app, say — keeps the draft's own.
        app(SaveDehuskingOrder::class)->handle($order, [
            'production_date' => '2026-09-20',
            'warehouse_id' => $this->main->id,
            'quantity' => '12',
            'labour_rate' => '4.00',
        ]);

        $this->assertSame($this->product('COCONUT-LARGE')->id, $order->inputs()->value('product_id'));
    }

    #[Test]
    public function simple_mode_asks_which_coconuts_and_records_that_size(): void
    {
        $this->actingAs($this->user)->get(route('kiosk.dehusking'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('kiosk/Production')
                ->has('recipes', 4)
                ->where('recipes.1.input.code', 'COCONUT-LARGE')
                ->where('recipes.1.input_stock.0.quantity', '500.000'));

        $this->actingAs($this->user)->post(route('kiosk.dehusking.store'), [
            'warehouse_id' => $this->main->id,
            'input_product_id' => $this->product('COCONUT-LARGE')->id,
            'quantity' => '50',
            'labour_rate' => '4.00',
        ])->assertSessionHasNoErrors();

        $this->assertSame('450.000', $this->quantity('COCONUT-LARGE'));
        $this->assertSame('50.000', $this->quantity('DEHUSKED-LARGE'));
    }
}
