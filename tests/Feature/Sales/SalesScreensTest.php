<?php

namespace Tests\Feature\Sales;

use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\DeliveryStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\PricingBasis;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SalesScreensTest extends TestCase
{
    use RefreshDatabase;

    private Product $coconut;

    private Customer $customer;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);

        $this->coconut = Product::factory()->create([
            'code' => 'COCONUT',
            'name' => 'Whole Coconut',
            'primary_uom_id' => $nos->id,
        ]);

        $this->customer = Customer::factory()->create(['code' => 'CUS-001']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
    }

    private function receive(string $quantity, string $unitCost): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->coconut->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                unitCost: $unitCost,
            ),
        ]);
    }

    #[Test]
    public function a_guest_cannot_access_the_sales_screens(): void
    {
        $order = SalesOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
        ]);
        $delivery = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
        ]);

        $this->get(route('sales-orders.index'))->assertRedirect(route('login'));
        $this->get(route('sales-orders.create'))->assertRedirect(route('login'));
        $this->get(route('sales-orders.show', $order))->assertRedirect(route('login'));
        $this->post(route('sales-orders.store'), [])->assertRedirect(route('login'));
        $this->patch(route('sales-orders.approve', $order))->assertRedirect(route('login'));

        $this->get(route('deliveries.index'))->assertRedirect(route('login'));
        $this->get(route('deliveries.create'))->assertRedirect(route('login'));
        $this->get(route('deliveries.show', $delivery))->assertRedirect(route('login'));
        $this->post(route('deliveries.store'), [])->assertRedirect(route('login'));
        $this->patch(route('deliveries.post', $delivery))->assertRedirect(route('login'));
        $this->patch(route('deliveries.reverse', $delivery))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_can_access_the_sales_screens(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('sales-orders.index'))->assertOk();
        $this->actingAs($user)->get(route('sales-orders.create'))->assertOk();
        $this->actingAs($user)->get(route('deliveries.index'))->assertOk();
        $this->actingAs($user)->get(route('deliveries.create'))->assertOk();
    }

    #[Test]
    public function a_sales_order_can_be_created_through_the_screen(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->post(route('sales-orders.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'order_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->coconut->id,
                    'quantity' => '10000',
                    'pricing_basis' => PricingBasis::Primary->value,
                    'unit_price' => '130',
                ],
            ],
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::firstOrFail();

        $this->assertMatchesRegularExpression('/^SO-\d{8}-\d{6}$/', $order->so_number);
        $this->assertSame(SalesOrderStatus::Draft, $order->status);
        $this->assertSame('1300000.000000', $order->lines()->first()->line_total);
    }

    #[Test]
    public function an_approved_sales_order_cannot_be_edited_through_the_screen(): void
    {
        $user = User::factory()->admin()->create();

        $order = SalesOrder::factory()->approved()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
        ]);
        SalesOrderLine::factory()->create([
            'sales_order_id' => $order->id,
            'product_id' => $this->coconut->id,
        ]);

        $this->actingAs($user)
            ->get(route('sales-orders.edit', $order))
            ->assertSessionHasErrors('status');

        $this->actingAs($user)->put(route('sales-orders.update', $order), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'order_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->coconut->id,
                    'quantity' => '5',
                    'pricing_basis' => PricingBasis::Primary->value,
                    'unit_price' => '1',
                ],
            ],
        ])->assertSessionHasErrors('status');

        // The original commercial values survive untouched.
        $this->assertSame('100.000', $order->lines()->first()->quantity);
        $this->assertSame('130.000000', $order->lines()->first()->unit_price);
    }

    #[Test]
    public function a_draft_delivery_created_through_the_screen_moves_no_stock(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('1000.000', '102.500000');

        $this->actingAs($user)->post(route('deliveries.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->coconut->id,
                    'quantity' => '100',
                    'pricing_basis' => PricingBasis::Primary->value,
                    'unit_price' => '130',
                ],
            ],
        ])->assertSessionHasNoErrors();

        $delivery = Delivery::firstOrFail();

        $this->assertMatchesRegularExpression('/^DN-\d{8}-\d{6}$/', $delivery->delivery_number);
        $this->assertSame(DeliveryStatus::Draft, $delivery->status);

        // Only the purchase receipt exists; nothing has been issued.
        $this->assertSame(1, InventoryTransaction::count());
        $this->assertSame('1000.000', app(StockQueryService::class)->quantityForProduct($this->coconut->id));
        $this->assertNull($delivery->lines()->first()->cost_of_goods_sold);
    }

    #[Test]
    public function posting_through_the_screen_issues_stock_and_records_profit(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('10000.000', '102.500000');

        $delivery = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
        ]);
        DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id,
            'product_id' => $this->coconut->id,
            'quantity' => '2000.000',
            'unit_price' => '130.000000',
        ]);

        $this->actingAs($user)
            ->patch(route('deliveries.post', $delivery))
            ->assertSessionHasNoErrors();

        $stock = app(StockQueryService::class);
        $this->assertSame('8000.000', $stock->quantityForProduct($this->coconut->id));

        $posted = $delivery->fresh(['lines']);
        $line = $posted->lines->first();

        $this->assertSame(DeliveryStatus::Posted, $posted->status);
        $this->assertSame($user->id, $posted->posted_by);
        $this->assertSame('260000.000000', $line->sales_value);
        $this->assertSame('102.500000', $line->inventory_unit_cost);
        $this->assertSame('205000.000000', $line->cost_of_goods_sold);
    }

    #[Test]
    public function the_delivery_screen_hides_cost_until_the_delivery_is_posted(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('1000.000', '102.500000');

        $delivery = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
        ]);
        DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id,
            'product_id' => $this->coconut->id,
            'quantity' => '100.000',
            'unit_price' => '130.000000',
        ]);

        // Draft: sales value is known, cost is not — no stock has moved yet.
        $this->actingAs($user)
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('sales/deliveries/Show')
                ->where('delivery.status', 'DRAFT')
                ->where('delivery.can_post', true)
                ->where('delivery.cogs_total', null)
                ->where('delivery.gross_profit', null)
                ->where('delivery.lines.0.inventory_unit_cost', null));

        $this->actingAs($user)->patch(route('deliveries.post', $delivery));

        $this->actingAs($user)
            ->get(route('deliveries.show', $delivery))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('delivery.status', 'POSTED')
                ->where('delivery.cogs_total', '10250.000000')
                ->where('delivery.gross_profit', '2750.000000')
                ->where('delivery.gross_margin', '21.15'));
    }

    #[Test]
    public function the_customer_page_shows_sales_history_without_a_receivable_balance(): void
    {
        $user = User::factory()->admin()->create();
        $this->receive('1000.000', '100.000000');

        $delivery = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
        ]);
        DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id,
            'product_id' => $this->coconut->id,
            'quantity' => '100.000',
            'unit_price' => '150.000000',
        ]);

        $this->actingAs($user)->patch(route('deliveries.post', $delivery));

        $this->actingAs($user)
            ->get(route('customers.show', $this->customer))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('customers/Show')
                ->has('salesHistory.deliveries', 1)
                ->where('salesHistory.delivered_value', '15000.000000')
                // Receivables belong to the finance module and must not appear here.
                ->missing('salesHistory.receivable_balance')
                ->missing('customer.balance'));
    }

    #[Test]
    public function sales_registers_no_destroy_routes(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): ?string => $route->getName())
            ->filter()
            ->filter(fn (string $name): bool => str_starts_with($name, 'sales-orders.')
                || str_starts_with($name, 'deliveries.'));

        $this->assertTrue($names->isNotEmpty());
        $this->assertCount(
            0,
            $names->filter(fn (string $name): bool => str_contains($name, 'destroy')),
            'Sales must expose no destroy route.'
        );
    }
}
