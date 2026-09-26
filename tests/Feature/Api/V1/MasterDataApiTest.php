<?php

namespace Tests\Feature\Api\V1;

use App\Enums\Permission;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Products, suppliers, customers and warehouses over the API.
 */
class MasterDataApiTest extends TestCase
{
    use RefreshDatabase;

    private UnitOfMeasure $nos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
    }

    private function actAs(Permission ...$permissions): User
    {
        $user = User::factory()->withPermissions(...$permissions)->create();
        Sanctum::actingAs($user, ['mobile-api']);

        return $user;
    }

    // ------------------------------------------------------------- products

    #[Test]
    public function the_product_list_requires_products_view(): void
    {
        Product::factory()->create(['primary_uom_id' => $this->nos->id]);

        $this->actAs(Permission::InventoryView);
        $this->getJson(route('api.v1.products.index'))->assertForbidden()->assertJsonStructure(['message']);

        $this->actAs(Permission::ProductsView);
        $this->getJson(route('api.v1.products.index'))->assertOk()->assertJsonCount(1, 'data')->assertJsonStructure(['meta' => ['total']]);
    }

    #[Test]
    public function a_product_is_shown_with_its_units_and_no_stock_field(): void
    {
        $product = Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => $this->nos->id]);
        $this->actAs(Permission::ProductsView);

        $this->getJson(route('api.v1.products.show', $product))
            ->assertOk()
            ->assertJsonPath('data.code', 'COCONUT')
            ->assertJsonPath('data.primary_uom.code', 'NOS')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonMissingPath('data.current_stock');
    }

    #[Test]
    public function products_filter_by_search_and_status(): void
    {
        Product::factory()->create(['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary_uom_id' => $this->nos->id]);
        Product::factory()->create(['code' => 'COPRA', 'name' => 'Copra', 'primary_uom_id' => $this->nos->id, 'is_active' => false]);
        $this->actAs(Permission::ProductsView);

        $this->getJson(route('api.v1.products.index', ['search' => 'COCONUT']))->assertJsonCount(1, 'data');
        $this->getJson(route('api.v1.products.index', ['status' => 'inactive']))->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'COPRA');
    }

    #[Test]
    public function creating_and_updating_a_product_requires_products_manage(): void
    {
        $payload = ['code' => 'shell', 'name' => 'Raw Shell', 'primary_uom_id' => $this->nos->id];

        $this->actAs(Permission::ProductsView);
        $this->postJson(route('api.v1.products.store'), $payload)->assertForbidden();

        $this->actAs(Permission::ProductsView, Permission::ProductsManage);
        $id = $this->postJson(route('api.v1.products.store'), $payload)
            ->assertCreated()
            ->assertJsonPath('data.code', 'SHELL')
            ->json('data.id');

        $this->putJson(route('api.v1.products.update', $id), ['code' => 'SHELL', 'name' => 'Coconut Shell', 'primary_uom_id' => $this->nos->id])
            ->assertOk()
            ->assertJsonPath('data.name', 'Coconut Shell');
    }

    #[Test]
    public function product_validation_errors_are_field_level_json(): void
    {
        $this->actAs(Permission::ProductsManage);

        $this->postJson(route('api.v1.products.store'), ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'name', 'primary_uom_id']);
    }

    #[Test]
    public function the_status_endpoint_sets_the_requested_state_so_a_retry_does_not_flip_it_back(): void
    {
        $product = Product::factory()->create(['primary_uom_id' => $this->nos->id]);
        $this->actAs(Permission::ProductsManage);

        $this->postJson(route('api.v1.products.status.update', $product), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson(route('api.v1.products.status.update', $product), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertFalse($product->fresh()->is_active);
        $this->postJson(route('api.v1.products.status.update', $product), [])->assertJsonValidationErrors('is_active');
    }

    #[Test]
    public function there_is_no_delete_endpoint_for_master_data(): void
    {
        $product = Product::factory()->create(['primary_uom_id' => $this->nos->id]);
        $this->actAs(Permission::ProductsManage);

        $this->deleteJson('/api/v1/products/'.$product->id)->assertStatus(405);
        $this->assertNotNull($product->fresh());
    }

    // ------------------------------------------------------------- suppliers and customers

    #[Test]
    public function supplier_endpoints_are_protected_and_a_manager_can_create_and_update(): void
    {
        $supplier = Supplier::factory()->create();

        $this->actAs(Permission::CustomersView);
        $this->getJson(route('api.v1.suppliers.index'))->assertForbidden();
        $this->getJson(route('api.v1.suppliers.show', $supplier))->assertForbidden();

        $this->actAs(Permission::SuppliersView);
        $this->getJson(route('api.v1.suppliers.show', $supplier))->assertOk()->assertJsonPath('data.id', $supplier->id);
        $this->postJson(route('api.v1.suppliers.store'), ['code' => 'SUP-9', 'name' => 'Nimal Traders'])->assertForbidden();

        $this->actAs(Permission::SuppliersManage);
        $id = $this->postJson(route('api.v1.suppliers.store'), ['code' => 'sup-9', 'name' => 'Nimal Traders', 'payment_terms_days' => 30])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SUP-9')
            ->assertJsonPath('data.payment_terms_days', 30)
            ->json('data.id');

        $this->putJson(route('api.v1.suppliers.update', $id), ['code' => 'SUP-9', 'name' => 'Nimal Traders (Pvt) Ltd', 'payment_terms_days' => 30])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nimal Traders (Pvt) Ltd');
    }

    #[Test]
    public function customer_endpoints_are_protected_and_the_credit_limit_is_an_exact_string(): void
    {
        $this->actAs(Permission::SuppliersManage);
        $this->postJson(route('api.v1.customers.store'), ['code' => 'CUS-1', 'name' => 'Kamal Stores'])->assertForbidden();

        $this->actAs(Permission::CustomersManage, Permission::CustomersView);
        $id = $this->postJson(route('api.v1.customers.store'), [
            'code' => 'CUS-1',
            'name' => 'Kamal Stores',
            'credit_limit' => '150000.50',
            'credit_days' => 30,
        ])->assertCreated()->json('data.id');

        $body = $this->getJson(route('api.v1.customers.show', $id))->assertOk()->json('data');

        $this->assertSame('150000.50', $body['credit_limit']);
        $this->assertArrayNotHasKey('balance', $body);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['created_at']);
    }

    // ------------------------------------------------------------- warehouses

    #[Test]
    public function warehouse_endpoints_are_protected(): void
    {
        $warehouse = Warehouse::factory()->default()->create();

        $this->actAs(Permission::ProductsView);
        $this->getJson(route('api.v1.warehouses.index'))->assertForbidden();

        $this->actAs(Permission::WarehousesView);
        $this->getJson(route('api.v1.warehouses.show', $warehouse))->assertOk()->assertJsonPath('data.is_default', true);
        $this->postJson(route('api.v1.warehouses.default.update', $warehouse))->assertForbidden();
    }

    #[Test]
    public function a_new_default_warehouse_goes_through_the_single_default_invariant(): void
    {
        $main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->actAs(Permission::WarehousesManage, Permission::WarehousesView);

        $store = $this->postJson(route('api.v1.warehouses.store'), ['code' => 'store', 'name' => 'Store', 'is_default' => true])
            ->assertCreated()
            ->assertJsonPath('data.code', 'STORE')
            ->assertJsonPath('data.is_default', true);

        $this->assertFalse($main->fresh()->is_default);
        $this->assertSame(1, Warehouse::where('is_default', true)->count());

        $this->postJson(route('api.v1.warehouses.default.update', $main))->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertFalse(Warehouse::find($store->json('data.id'))->is_default);
    }

    #[Test]
    public function the_default_warehouse_cannot_be_deactivated(): void
    {
        $main = Warehouse::factory()->default()->create();
        $this->actAs(Permission::WarehousesManage);

        $this->postJson(route('api.v1.warehouses.status.update', $main), ['is_active' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($main->fresh()->is_active);
    }
}
