<?php

namespace Tests\Feature\Purchasing;

use App\Enums\GoodsReceiptStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchasingScreensTest extends TestCase
{
    use RefreshDatabase;

    private Product $coconut;

    private Supplier $supplier;

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

        $this->supplier = Supplier::factory()->create(['code' => 'SUP-001']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
    }

    #[Test]
    public function a_guest_cannot_access_the_purchasing_screens(): void
    {
        $order = PurchaseOrder::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
        ]);
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
        ]);

        $this->get(route('purchase-orders.index'))->assertRedirect(route('login'));
        $this->get(route('purchase-orders.create'))->assertRedirect(route('login'));
        $this->get(route('purchase-orders.show', $order))->assertRedirect(route('login'));
        $this->post(route('purchase-orders.store'), [])->assertRedirect(route('login'));
        $this->patch(route('purchase-orders.approve', $order))->assertRedirect(route('login'));

        $this->get(route('goods-receipts.index'))->assertRedirect(route('login'));
        $this->get(route('goods-receipts.create'))->assertRedirect(route('login'));
        $this->get(route('goods-receipts.show', $receipt))->assertRedirect(route('login'));
        $this->post(route('goods-receipts.store'), [])->assertRedirect(route('login'));
        $this->patch(route('goods-receipts.post', $receipt))->assertRedirect(route('login'));
        $this->patch(route('goods-receipts.reverse', $receipt))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_can_access_the_purchasing_screens(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('purchase-orders.index'))->assertOk();
        $this->actingAs($user)->get(route('purchase-orders.create'))->assertOk();
        $this->actingAs($user)->get(route('goods-receipts.index'))->assertOk();
        $this->actingAs($user)->get(route('goods-receipts.create'))->assertOk();
    }

    #[Test]
    public function a_purchase_order_can_be_created_through_the_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('purchase-orders.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'order_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $this->coconut->id, 'quantity' => '1000', 'unit_price' => '100'],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $order = PurchaseOrder::firstOrFail();
        $this->assertMatchesRegularExpression('/^PO-\d{8}-\d{6}$/', $order->po_number);
        $this->assertSame(PurchaseOrderStatus::Draft, $order->status);
        $this->assertSame('100000.000000', $order->lines()->first()->line_total);
    }

    #[Test]
    public function an_approved_purchase_order_cannot_be_edited_through_the_screen(): void
    {
        $user = User::factory()->create();

        $order = PurchaseOrder::factory()->approved()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
        ]);
        PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $order->id,
            'product_id' => $this->coconut->id,
        ]);

        $this->actingAs($user)
            ->get(route('purchase-orders.edit', $order))
            ->assertSessionHasErrors('status');

        $this->actingAs($user)->put(route('purchase-orders.update', $order), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'order_date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $this->coconut->id, 'quantity' => '5', 'unit_price' => '1'],
            ],
        ])->assertSessionHasErrors('status');

        // The original line is untouched.
        $this->assertSame('100.000', $order->lines()->first()->quantity);
    }

    #[Test]
    public function a_draft_goods_receipt_created_through_the_screen_moves_no_stock(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('goods-receipts.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'receipt_date' => now()->toDateString(),
            'transport_cost' => '0',
            'loading_cost' => '0',
            'other_cost' => '0',
            'lines' => [
                ['product_id' => $this->coconut->id, 'quantity' => '100', 'unit_price' => '10'],
            ],
        ])->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::firstOrFail();
        $this->assertMatchesRegularExpression('/^GRN-\d{8}-\d{6}$/', $receipt->grn_number);
        $this->assertSame(GoodsReceiptStatus::Draft, $receipt->status);

        // Nothing has reached the ledger.
        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame('0.000', app(StockQueryService::class)->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function posting_through_the_screen_moves_stock_and_matches_the_receipt_values(): void
    {
        $user = User::factory()->create();

        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'transport_cost' => '20000.000000',
            'loading_cost' => '5000.000000',
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->coconut->id,
            'quantity' => '10000.000',
            'unit_price' => '100.000000',
        ]);

        $this->actingAs($user)
            ->patch(route('goods-receipts.post', $receipt))
            ->assertSessionHasNoErrors();

        $stock = app(StockQueryService::class);

        // Inventory must match the receipt exactly: 1,025,000 over 10,000 units.
        $this->assertSame('10000.000', $stock->quantityForProduct($this->coconut->id));
        $this->assertSame('1025000.000000', $stock->valueForProduct($this->coconut->id));
        $this->assertSame('102.500000', $stock->weightedAverageCostForProduct($this->coconut->id));

        $posted = $receipt->fresh();
        $this->assertSame(GoodsReceiptStatus::Posted, $posted->status);
        $this->assertSame($user->id, $posted->posted_by);
    }

    #[Test]
    public function the_goods_receipt_screen_shows_a_cost_preview_for_a_draft(): void
    {
        $user = User::factory()->create();

        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'transport_cost' => '25000.000000',
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->coconut->id,
            'quantity' => '10000.000',
            'unit_price' => '100.000000',
        ]);

        $this->actingAs($user)
            ->get(route('goods-receipts.show', $receipt))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('purchasing/goods-receipts/Show')
                ->where('receipt.status', 'DRAFT')
                ->where('receipt.can_post', true)
                // Preview only — nothing is stored on the line yet.
                ->where('receipt.lines.0.final_unit_cost', '102.500000'));

        $this->assertSame('0.000000', $receipt->lines()->first()->final_unit_cost);
    }

    #[Test]
    public function the_supplier_page_shows_purchase_history_without_a_payable_balance(): void
    {
        $user = User::factory()->create();

        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->coconut->id,
            'quantity' => '100.000',
            'unit_price' => '10.000000',
        ]);

        $this->actingAs($user)->patch(route('goods-receipts.post', $receipt));

        $this->actingAs($user)
            ->get(route('suppliers.show', $this->supplier))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('suppliers/Show')
                ->has('purchaseHistory.goods_receipts', 1)
                ->where('purchaseHistory.total_received_value', '1000.000000')
                // Payables belong to the finance module and must not appear here.
                ->missing('purchaseHistory.payable_balance')
                ->missing('supplier.balance'));
    }

    #[Test]
    public function purchasing_registers_no_destroy_routes(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutes())
            ->map(fn ($route): ?string => $route->getName())
            ->filter()
            ->filter(fn (string $name): bool => str_starts_with($name, 'purchase-orders.')
                || str_starts_with($name, 'goods-receipts.'));

        $this->assertTrue($names->isNotEmpty());
        $this->assertCount(
            0,
            $names->filter(fn (string $name): bool => str_contains($name, 'destroy')),
            'Purchasing must expose no destroy route.'
        );
    }
}
