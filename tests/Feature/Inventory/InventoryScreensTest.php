<?php

namespace Tests\Feature\Inventory;

use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InventoryScreensTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->product = Product::factory()->create([
            'code' => 'HUSK-7C',
            'name' => '7C',
            'primary_uom_id' => $kg->id,
        ]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
    }

    private function seedMovement(): InventoryTransaction
    {
        return app(PostInventoryTransaction::class)->handle(
            InventoryTransactionType::PurchaseReceipt,
            [
                new PendingInventoryLine(
                    productId: $this->product->id,
                    warehouseId: $this->main->id,
                    direction: InventoryDirection::In,
                    quantity: '100.000',
                    unitCost: '25.000000',
                ),
            ],
        );
    }

    #[Test]
    public function an_unauthenticated_user_cannot_access_the_inventory_screens(): void
    {
        $this->get(route('inventory.stock.index'))->assertRedirect(route('login'));
        $this->get(route('inventory.ledger.index'))->assertRedirect(route('login'));
        $this->get(route('inventory.lots.index'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_can_view_the_stock_screen(): void
    {
        $user = User::factory()->admin()->create();
        $this->seedMovement();

        $response = $this->actingAs($user)->get(route('inventory.stock.index'));

        $response->assertOk();
        $response->assertSee('HUSK-7C');
        $response->assertSee('MAIN');
    }

    #[Test]
    public function an_authenticated_user_can_view_the_ledger(): void
    {
        $user = User::factory()->admin()->create();
        $transaction = $this->seedMovement();

        $response = $this->actingAs($user)->get(route('inventory.ledger.index'));

        $response->assertOk();
        $response->assertSee($transaction->reference);
    }

    #[Test]
    public function an_authenticated_user_can_view_the_lots_screen(): void
    {
        $user = User::factory()->admin()->create();
        $lot = InventoryLot::factory()->create(['product_id' => $this->product->id]);

        $response = $this->actingAs($user)->get(route('inventory.lots.index'));

        $response->assertOk();
        $response->assertSee($lot->lot_number);
    }

    #[Test]
    public function the_stock_screen_hides_inactive_products_by_default(): void
    {
        $user = User::factory()->admin()->create();
        $this->seedMovement();

        $this->product->update(['is_active' => false]);

        // Assert on the Inertia props, not the rendered HTML: the product filter dropdown
        // always lists every product, so assertSee/assertDontSee cannot distinguish a row
        // from a dropdown option.
        $this->actingAs($user)
            ->get(route('inventory.stock.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('inventory/StockOnHand')
                ->has('rows', 0));

        $this->actingAs($user)
            ->get(route('inventory.stock.index', ['include_inactive' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('rows', 1)
                ->where('rows.0.product_code', 'HUSK-7C')
                ->where('rows.0.product_is_active', false));
    }

    #[Test]
    public function the_ledger_can_be_filtered_by_product(): void
    {
        $user = User::factory()->admin()->create();
        $this->seedMovement();

        $other = Product::factory()->create([
            'code' => 'HUSK-9C',
            'primary_uom_id' => $this->product->primary_uom_id,
        ]);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $other->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: '5.000',
                unitCost: '1.000000',
            ),
        ]);

        $this->actingAs($user)
            ->get(route('inventory.ledger.index', ['product_id' => $this->product->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('inventory/StockLedger')
                ->has('lines.data', 1)
                ->where('lines.data.0.product_code', 'HUSK-7C'));
    }

    #[Test]
    public function inventory_transactions_cannot_be_changed_through_any_route(): void
    {
        $user = User::factory()->admin()->create();
        $transaction = $this->seedMovement();

        // The inventory module exposes read-only GET routes only. Nothing that could
        // modify a posted transaction is registered at all. Damaged stock is a business
        // document of its own — recorded and reversed, never edited or deleted.
        $allRoutes = collect(app('router')->getRoutes()->getRoutes());
        $routes = $allRoutes->filter(fn ($route): bool => str_starts_with($route->uri(), 'inventory')
            && ! str_starts_with($route->uri(), 'inventory/damages'));

        foreach ($allRoutes->filter(fn ($route): bool => str_starts_with($route->uri(), 'inventory/damages')) as $route) {
            $this->assertSame([], array_values(array_intersect($route->methods(), ['PUT', 'DELETE'])), "Route [{$route->uri()}] must not edit or delete.");
        }

        $this->assertTrue($routes->isNotEmpty(), 'Expected inventory routes to exist.');

        foreach ($routes as $route) {
            $methods = array_diff($route->methods(), ['HEAD']);

            $this->assertSame(
                ['GET'],
                array_values($methods),
                "Route [{$route->uri()}] must be read-only, got: ".implode(',', $methods)
            );
        }

        // And the write verbs genuinely 405/404 rather than silently working.
        foreach ([
            route('inventory.stock.index'),
            route('inventory.ledger.index'),
            route('inventory.lots.index'),
        ] as $url) {
            $this->actingAs($user)->post($url)->assertStatus(405);
            $this->actingAs($user)->put($url)->assertStatus(405);
            $this->actingAs($user)->delete($url)->assertStatus(405);
        }

        $this->assertDatabaseHas('inventory_transactions', [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
        ]);
    }

    #[Test]
    public function the_inventory_module_registers_no_destroy_route(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): ?string => $route->getName())
            ->filter()
            ->filter(fn (string $name): bool => str_starts_with($name, 'inventory.'));

        $this->assertTrue($names->isNotEmpty());
        $this->assertCount(
            0,
            $names->filter(fn (string $name): bool => str_contains($name, 'destroy')),
            'Inventory must expose no destroy route.'
        );
    }
}
