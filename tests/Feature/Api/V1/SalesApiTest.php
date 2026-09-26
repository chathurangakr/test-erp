<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\DeliveryStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\Permission;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sales over the API — the same actions as the website, proven end to end.
 */
class SalesApiTest extends TestCase
{
    use RefreshDatabase;

    private Product $coconut;

    private Product $shell;

    private Customer $customer;

    private Warehouse $main;

    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary_uom_id' => $nos->id]);
        $this->shell = Product::factory()->create([
            'code' => 'RAW-SHELL',
            'name' => 'Raw Coconut Shell',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => $kg->id,
        ]);
        $this->customer = Customer::factory()->create(['code' => 'CUS-001']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->seller = User::factory()->admin()->create(['name' => 'Mobile Seller']);
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user, ['mobile-api']);
    }

    private function receive(Product $product, string $quantity, string $unitCost, ?string $secondary = null): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $product->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                secondaryQuantity: $secondary,
                unitCost: $unitCost,
            ),
        ]);
    }

    private function stock(Product $product): string
    {
        return app(StockQueryService::class)->quantityForProduct($product->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryPayload(array $lines, array $extra = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => '2026-09-15',
            'lines' => $lines,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function coconutLine(string $quantity = '400', string $price = '130'): array
    {
        return ['product_id' => $this->coconut->id, 'quantity' => $quantity, 'pricing_basis' => 'PRIMARY', 'unit_price' => $price];
    }

    // ------------------------------------------------------------- sales orders

    #[Test]
    public function a_sales_order_is_created_and_approved_through_the_api(): void
    {
        $this->actAs($this->seller);

        $created = $this->postJson(route('api.v1.sales-orders.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'order_date' => '2026-09-10',
            'lines' => [$this->coconutLine('2000', '130')],
        ])->assertCreated();

        $created->assertJsonPath('data.status.value', 'DRAFT')
            ->assertJsonPath('data.lines.0.quantity', '2000.000')
            ->assertJsonPath('data.lines.0.pricing_basis.value', 'PRIMARY')
            ->assertJsonPath('data.lines.0.line_total', '260000.000000')
            ->assertJsonPath('data.sales_total', '260000.000000')
            ->assertJsonPath('data.created_by.name', 'Mobile Seller');

        $id = $created->json('data.id');

        $this->postJson(route('api.v1.sales-orders.approve', $id))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'APPROVED')
            ->assertJsonPath('data.approved_by.name', 'Mobile Seller');

        $this->assertSame(SalesOrderStatus::Approved, SalesOrder::find($id)->status);
        $this->assertSame($this->seller->id, SalesOrder::find($id)->approved_by);
        $this->assertSame('0.000', $this->stock($this->coconut), 'A sales order never moves stock.');

        $this->postJson(route('api.v1.sales-orders.approve', $id))->assertStatus(409);
    }

    #[Test]
    public function a_sales_order_is_cancelled_through_the_api(): void
    {
        $this->actAs($this->seller);
        $order = SalesOrder::factory()->create(['status' => SalesOrderStatus::Draft]);

        $this->postJson(route('api.v1.sales-orders.cancel', $order))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'CANCELLED')
            ->assertJsonPath('data.cancelled_by.name', 'Mobile Seller');
    }

    // ------------------------------------------------------------- deliveries

    #[Test]
    public function a_draft_delivery_changes_no_stock_and_reports_no_cost(): void
    {
        $this->receive($this->coconut, '1000', '100');
        $this->actAs($this->seller);

        $this->postJson(route('api.v1.deliveries.store'), $this->deliveryPayload([$this->coconutLine()]))
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'DRAFT')
            ->assertJsonPath('data.lines.0.cost_of_goods_sold', null)
            ->assertJsonPath('data.cost_of_goods_sold', null)
            ->assertJsonPath('data.gross_profit', null);

        $this->assertSame('1000.000', $this->stock($this->coconut));
        $this->assertSame(1, InventoryTransaction::count());
    }

    #[Test]
    public function posting_a_delivery_issues_stock_exactly_once_at_the_weighted_average(): void
    {
        // 1,000 @ 100 and 1,000 @ 110 → weighted average 105.
        $this->receive($this->coconut, '1000', '100');
        $this->receive($this->coconut, '1000', '110');
        $this->actAs($this->seller);

        $id = $this->postJson(route('api.v1.deliveries.store'), $this->deliveryPayload([$this->coconutLine('400', '130')]))->json('data.id');

        $posted = $this->postJson(route('api.v1.deliveries.post', $id))->assertOk();

        // Sales 400 × 130 = 52,000; COGS 400 × 105 = 42,000; profit 10,000.
        $posted->assertJsonPath('data.status.value', 'POSTED')
            ->assertJsonPath('data.lines.0.sales_value', '52000.000000')
            ->assertJsonPath('data.lines.0.inventory_unit_cost', '105.000000')
            ->assertJsonPath('data.lines.0.cost_of_goods_sold', '42000.000000')
            ->assertJsonPath('data.lines.0.gross_profit', '10000.000000')
            ->assertJsonPath('data.cost_of_goods_sold', '42000.000000')
            ->assertJsonPath('data.gross_profit', '10000.000000')
            ->assertJsonPath('data.gross_margin_percent', '19.23')
            ->assertJsonPath('data.posted_by.name', 'Mobile Seller');

        $this->assertSame('1600.000', $this->stock($this->coconut));
        $this->assertSame($this->seller->id, Delivery::find($id)->posted_by);

        // A phone retrying the same request cannot issue the stock twice.
        $this->postJson(route('api.v1.deliveries.post', $id))->assertStatus(409)->assertJsonStructure(['message', 'errors' => ['status']]);
        $this->assertSame('1600.000', $this->stock($this->coconut));
        $this->assertSame(3, InventoryTransaction::count());
    }

    #[Test]
    public function raw_shell_priced_per_kg_reports_the_stored_sales_value_without_conversion(): void
    {
        $this->receive($this->shell, '500', '20', '1750');
        $this->actAs($this->seller);

        $id = $this->postJson(route('api.v1.deliveries.store'), $this->deliveryPayload([[
            'product_id' => $this->shell->id,
            'quantity' => '500',
            'secondary_quantity' => '1750',
            'pricing_basis' => 'SECONDARY',
            'unit_price' => '80',
        ]]))->json('data.id');

        $line = $this->postJson(route('api.v1.deliveries.post', $id))->assertOk()->json('data.lines.0');

        // Revenue follows KG (1,750 × 80); cost follows the NOS issue (the whole 10,000 on hand).
        $this->assertSame('500.000', $line['quantity']);
        $this->assertSame('1750.000', $line['secondary_quantity']);
        $this->assertSame('SECONDARY', $line['pricing_basis']['value']);
        $this->assertSame('140000.000000', $line['sales_value']);
        $this->assertSame(DeliveryLine::query()->sole()->sales_value, $line['sales_value']);
        $this->assertSame('10000.000000', $line['cost_of_goods_sold']);
    }

    #[Test]
    public function a_delivery_is_reversed_through_the_existing_action(): void
    {
        $this->receive($this->coconut, '1000', '100');
        $this->actAs($this->seller);

        $id = $this->postJson(route('api.v1.deliveries.store'), $this->deliveryPayload([$this->coconutLine()]))->json('data.id');
        $this->postJson(route('api.v1.deliveries.post', $id))->assertOk();

        $this->postJson(route('api.v1.deliveries.reverse', $id))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'REVERSED')
            ->assertJsonPath('data.reversed_by.name', 'Mobile Seller');

        $this->assertSame('1000.000', $this->stock($this->coconut));
        $this->assertSame('100000.000000', app(StockQueryService::class)->valueForProduct($this->coconut->id));
    }

    #[Test]
    public function a_draft_delivery_is_updated_and_cancelled_and_a_posted_one_cannot_be_edited(): void
    {
        $this->receive($this->coconut, '1000', '100');
        $this->actAs($this->seller);

        $draft = $this->postJson(route('api.v1.deliveries.store'), $this->deliveryPayload([$this->coconutLine()]))->json('data.id');

        $this->putJson(route('api.v1.deliveries.update', $draft), $this->deliveryPayload([$this->coconutLine('250', '140')]))
            ->assertOk()
            ->assertJsonPath('data.lines.0.quantity', '250.000')
            ->assertJsonPath('data.lines.0.unit_price', '140.000000');

        $this->postJson(route('api.v1.deliveries.cancel', $draft))->assertOk()->assertJsonPath('data.status.value', 'CANCELLED');

        $posted = $this->postJson(route('api.v1.deliveries.store'), $this->deliveryPayload([$this->coconutLine()]))->json('data.id');
        $this->postJson(route('api.v1.deliveries.post', $posted))->assertOk();

        $this->putJson(route('api.v1.deliveries.update', $posted), $this->deliveryPayload([$this->coconutLine('1', '1')]))->assertStatus(409);
        $this->postJson(route('api.v1.deliveries.cancel', $posted))->assertStatus(409);
    }

    #[Test]
    public function issuing_more_than_is_on_hand_is_refused_and_moves_nothing(): void
    {
        $this->receive($this->coconut, '100', '100');
        $this->actAs($this->seller);

        $id = $this->postJson(route('api.v1.deliveries.store'), $this->deliveryPayload([$this->coconutLine('400')]))->json('data.id');

        $this->postJson(route('api.v1.deliveries.post', $id))->assertUnprocessable()->assertJsonStructure(['message', 'errors']);

        $this->assertSame('100.000', $this->stock($this->coconut));
        $this->assertSame(DeliveryStatus::Draft, Delivery::find($id)->status);
    }

    // ------------------------------------------------------------- permissions

    #[Test]
    public function a_sales_clerk_may_draft_a_delivery_but_only_a_storekeeper_may_post_it(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->receive($this->coconut, '1000', '100');

        $this->actAs(User::factory()->withRoles('SALES')->create());
        $id = $this->postJson(route('api.v1.deliveries.store'), $this->deliveryPayload([$this->coconutLine()]))->assertCreated()->json('data.id');

        $this->postJson(route('api.v1.deliveries.post', $id))->assertForbidden()->assertJsonStructure(['message']);
        $this->assertSame('1000.000', $this->stock($this->coconut));

        $this->actAs(User::factory()->withRoles('STOREKEEPER')->create());
        $this->postJson(route('api.v1.deliveries.post', $id))->assertOk();
        $this->assertSame('600.000', $this->stock($this->coconut));
    }

    #[Test]
    public function a_sales_clerk_cannot_approve_a_sales_order(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $order = SalesOrder::factory()->create(['status' => SalesOrderStatus::Draft]);

        $this->actAs(User::factory()->withRoles('SALES')->create());

        $this->postJson(route('api.v1.sales-orders.approve', $order))->assertForbidden();
        $this->assertSame(SalesOrderStatus::Draft, $order->fresh()->status);
    }

    #[Test]
    public function a_view_only_user_can_read_but_not_create(): void
    {
        $this->actAs(User::factory()->withPermissions(Permission::SalesView)->create());

        $this->getJson(route('api.v1.deliveries.index'))->assertOk();
        $this->getJson(route('api.v1.sales-orders.index'))->assertOk();
        $this->postJson(route('api.v1.deliveries.store'), $this->deliveryPayload([$this->coconutLine()]))->assertForbidden();
    }

    // ------------------------------------------------------------- wire format and filters

    #[Test]
    public function decimals_are_strings_and_lists_filter_by_customer_status_and_date(): void
    {
        $this->receive($this->coconut, '1000', '100');
        $this->actAs($this->seller);

        $id = $this->postJson(route('api.v1.deliveries.store'), $this->deliveryPayload([$this->coconutLine()]))->json('data.id');
        $body = $this->postJson(route('api.v1.deliveries.post', $id))->json('data');

        $this->assertIsString($body['lines'][0]['quantity']);
        $this->assertIsString($body['lines'][0]['cost_of_goods_sold']);
        $this->assertSame('2026-09-15', $body['delivery_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['posted_at']);

        Delivery::factory()->create(['delivery_date' => '2026-08-01']);

        $this->getJson(route('api.v1.deliveries.index', ['customer_id' => $this->customer->id]))->assertJsonCount(1, 'data');
        $this->getJson(route('api.v1.deliveries.index', ['status' => 'POSTED']))->assertJsonCount(1, 'data');
        $this->getJson(route('api.v1.deliveries.index', ['from' => '2026-09-01', 'to' => '2026-09-30']))->assertJsonCount(1, 'data');
        $this->getJson(route('api.v1.deliveries.index', ['per_page' => 1]))->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.total', 2);
    }
}
