<?php

namespace Tests\Feature\Api\V1;

use App\Enums\GoodsReceiptStatus;
use App\Enums\Permission;
use App\Enums\PurchaseOrderStatus;
use App\Models\GoodsReceipt;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
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
 * Purchasing over the API — the same actions as the website, proven end to end.
 *
 * Also the home of the wire-format checks (decimal strings, ISO dates, pagination, error
 * shapes), because purchasing exercises all of them.
 */
class PurchasingApiTest extends TestCase
{
    use RefreshDatabase;

    private Product $coconut;

    private Supplier $supplier;

    private Warehouse $main;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary_uom_id' => $nos->id]);
        $this->supplier = Supplier::factory()->create(['code' => 'SUP-001']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->clerk = User::factory()->admin()->create(['name' => 'Mobile Clerk']);
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user, ['mobile-api']);
    }

    /**
     * @return array<string, mixed>
     */
    private function grnPayload(array $extra = []): array
    {
        return array_merge([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'receipt_date' => '2026-09-15',
            'transport_cost' => '20000',
            'loading_cost' => '5000',
            'other_cost' => '0',
            'lines' => [
                ['product_id' => $this->coconut->id, 'quantity' => '10000', 'unit_price' => '100'],
            ],
        ], $extra);
    }

    private function stock(): string
    {
        return app(StockQueryService::class)->quantityForProduct($this->coconut->id);
    }

    // ------------------------------------------------------------- purchase orders

    #[Test]
    public function a_purchase_order_is_created_and_approved_through_the_api(): void
    {
        $this->actAs($this->clerk);

        $created = $this->postJson(route('api.v1.purchase-orders.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'order_date' => '2026-09-10',
            'lines' => [['product_id' => $this->coconut->id, 'quantity' => '10000', 'unit_price' => '102.5']],
        ])->assertCreated();

        $created->assertJsonPath('data.status.value', 'DRAFT')
            ->assertJsonPath('data.lines.0.quantity', '10000.000')
            ->assertJsonPath('data.lines.0.unit_price', '102.500000')
            ->assertJsonPath('data.lines.0.line_total', '1025000.000000')
            ->assertJsonPath('data.material_total', '1025000.000000')
            ->assertJsonPath('data.created_by.name', 'Mobile Clerk');

        $id = $created->json('data.id');

        $this->postJson(route('api.v1.purchase-orders.approve', $id))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'APPROVED')
            ->assertJsonPath('data.approved_by.name', 'Mobile Clerk');

        $this->assertSame(PurchaseOrderStatus::Approved, PurchaseOrder::find($id)->status);
        $this->assertSame($this->clerk->id, PurchaseOrder::find($id)->approved_by);
        $this->assertSame('0.000', $this->stock(), 'A purchase order never creates stock.');
    }

    #[Test]
    public function approving_twice_is_a_409_conflict(): void
    {
        $this->actAs($this->clerk);
        $order = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Approved]);

        $this->postJson(route('api.v1.purchase-orders.approve', $order))
            ->assertStatus(409)
            ->assertJsonStructure(['message', 'errors' => ['status']]);
    }

    #[Test]
    public function an_approved_order_cannot_be_edited_through_the_api(): void
    {
        $this->actAs($this->clerk);
        $order = PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Approved]);

        $this->putJson(route('api.v1.purchase-orders.update', $order), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'order_date' => '2026-09-10',
            'lines' => [['product_id' => $this->coconut->id, 'quantity' => '1', 'unit_price' => '1']],
        ])->assertStatus(409);
    }

    // ------------------------------------------------------------- goods receipts

    #[Test]
    public function a_draft_goods_receipt_changes_no_stock(): void
    {
        $this->actAs($this->clerk);

        $this->postJson(route('api.v1.goods-receipts.store'), $this->grnPayload())
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'DRAFT')
            ->assertJsonPath('data.lines.0.final_unit_cost', null);

        $this->assertSame('0.000', $this->stock());
        $this->assertSame(0, InventoryTransaction::count());
    }

    #[Test]
    public function posting_a_goods_receipt_receives_stock_exactly_once_at_the_exact_landed_cost(): void
    {
        $this->actAs($this->clerk);
        $id = $this->postJson(route('api.v1.goods-receipts.store'), $this->grnPayload())->json('data.id');

        $posted = $this->postJson(route('api.v1.goods-receipts.post', $id))->assertOk();

        // 10,000 × 100 = 1,000,000 material + 25,000 landed = 1,025,000 → 102.500000 each.
        $posted->assertJsonPath('data.status.value', 'POSTED')
            ->assertJsonPath('data.lines.0.material_cost', '1000000.000000')
            ->assertJsonPath('data.lines.0.allocated_landed_cost', '25000.000000')
            ->assertJsonPath('data.lines.0.final_unit_cost', '102.500000')
            ->assertJsonPath('data.landed_cost_total', '25000.000000')
            ->assertJsonPath('data.posted_by.name', 'Mobile Clerk');

        $this->assertSame('10000.000', $this->stock());
        $this->assertSame('1025000.000000', app(StockQueryService::class)->valueForProduct($this->coconut->id));

        // A phone retrying the same request cannot receive the stock twice.
        $this->postJson(route('api.v1.goods-receipts.post', $id))->assertStatus(409);
        $this->assertSame('10000.000', $this->stock());
        $this->assertSame(1, InventoryTransaction::count());
    }

    #[Test]
    public function a_goods_receipt_is_reversed_through_the_existing_action(): void
    {
        $this->actAs($this->clerk);
        $id = $this->postJson(route('api.v1.goods-receipts.store'), $this->grnPayload())->json('data.id');
        $this->postJson(route('api.v1.goods-receipts.post', $id))->assertOk();

        $this->postJson(route('api.v1.goods-receipts.reverse', $id))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'REVERSED')
            ->assertJsonPath('data.reversed_by.name', 'Mobile Clerk');

        $this->assertSame('0.000', $this->stock());
        $this->assertSame('0.000000', app(StockQueryService::class)->valueForProduct($this->coconut->id));
    }

    #[Test]
    public function a_draft_goods_receipt_is_cancelled_and_a_posted_one_is_refused(): void
    {
        $this->actAs($this->clerk);
        $draft = $this->postJson(route('api.v1.goods-receipts.store'), $this->grnPayload())->json('data.id');

        $this->postJson(route('api.v1.goods-receipts.cancel', $draft))->assertOk()->assertJsonPath('data.status.value', 'CANCELLED');

        $posted = $this->postJson(route('api.v1.goods-receipts.store'), $this->grnPayload())->json('data.id');
        $this->postJson(route('api.v1.goods-receipts.post', $posted));
        $this->postJson(route('api.v1.goods-receipts.cancel', $posted))->assertStatus(409);
    }

    // ------------------------------------------------------------- permissions

    #[Test]
    public function a_purchasing_clerk_may_draft_but_not_approve_or_post(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $clerk = User::factory()->withRoles('PURCHASING')->create();
        $this->actAs($clerk);

        $id = $this->postJson(route('api.v1.goods-receipts.store'), $this->grnPayload())->assertCreated()->json('data.id');

        $this->postJson(route('api.v1.goods-receipts.post', $id))->assertForbidden()->assertJsonStructure(['message']);
        $this->assertSame(GoodsReceiptStatus::Draft, GoodsReceipt::find($id)->status);
        $this->assertSame('0.000', $this->stock());
    }

    #[Test]
    public function multiple_roles_combine_so_storekeeper_and_purchasing_can_draft_and_post(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actAs(User::factory()->withRoles('PURCHASING', 'STOREKEEPER')->create());

        $id = $this->postJson(route('api.v1.goods-receipts.store'), $this->grnPayload())->json('data.id');
        $this->postJson(route('api.v1.goods-receipts.post', $id))->assertOk();
    }

    #[Test]
    public function a_view_only_user_can_read_but_not_create(): void
    {
        $this->actAs(User::factory()->withPermissions(Permission::PurchasingView)->create());

        $this->getJson(route('api.v1.goods-receipts.index'))->assertOk();
        $this->postJson(route('api.v1.goods-receipts.store'), $this->grnPayload())->assertForbidden();
    }

    // ------------------------------------------------------------- wire format

    #[Test]
    public function validation_errors_are_field_level_json(): void
    {
        $this->actAs($this->clerk);

        $this->postJson(route('api.v1.goods-receipts.store'), $this->grnPayload(['lines' => [], 'supplier_id' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines', 'supplier_id']);
    }

    #[Test]
    public function a_missing_record_is_a_json_404_that_names_no_model_class(): void
    {
        $this->actAs($this->clerk);

        $response = $this->getJson(route('api.v1.goods-receipts.show', 999999))->assertNotFound();

        $response->assertJsonPath('message', 'The requested record was not found.');
        $this->assertStringNotContainsString('App\\Models', $response->getContent());
    }

    #[Test]
    public function decimals_are_strings_and_timestamps_are_iso_8601_utc(): void
    {
        $this->actAs($this->clerk);
        $id = $this->postJson(route('api.v1.goods-receipts.store'), $this->grnPayload())->json('data.id');
        $body = $this->postJson(route('api.v1.goods-receipts.post', $id))->json('data');

        $this->assertIsString($body['lines'][0]['quantity']);
        $this->assertIsString($body['lines'][0]['final_unit_cost']);
        $this->assertSame('2026-09-15', $body['receipt_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['posted_at']);
    }

    #[Test]
    public function lists_paginate_with_metadata_and_cap_the_page_size(): void
    {
        $this->actAs($this->clerk);
        PurchaseOrder::factory()->count(3)->create();

        $this->getJson(route('api.v1.purchase-orders.index', ['per_page' => 2]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);

        $this->getJson(route('api.v1.purchase-orders.index', ['per_page' => 5000]))
            ->assertJsonPath('meta.per_page', 100);
    }

    #[Test]
    public function lists_filter_by_status_and_supplier(): void
    {
        $this->actAs($this->clerk);
        PurchaseOrder::factory()->create(['supplier_id' => $this->supplier->id, 'status' => PurchaseOrderStatus::Draft]);
        PurchaseOrder::factory()->create(['status' => PurchaseOrderStatus::Approved]);

        $this->getJson(route('api.v1.purchase-orders.index', ['supplier_id' => $this->supplier->id]))->assertJsonCount(1, 'data');
        $this->getJson(route('api.v1.purchase-orders.index', ['status' => 'APPROVED']))->assertJsonCount(1, 'data');
    }
}
