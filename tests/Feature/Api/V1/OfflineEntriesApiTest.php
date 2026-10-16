<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Api\RecordApiSubmission;
use App\Actions\Kiosk\RecordKioskEntry;
use App\Data\Api\SubmittedEntry;
use App\Enums\DeliveryStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\Permission;
use App\Enums\ProductionStatus;
use App\Models\ApiSubmission;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Offline entries from the phone: save and post in one call, recorded exactly once per
 * Idempotency-Key however many times the phone sends them. See docs/API_V1.md §17.
 */
class OfflineEntriesApiTest extends TestCase
{
    use RefreshDatabase;

    private Product $coconut;

    private Product $husk;

    private Warehouse $main;

    private Supplier $supplier;

    private Customer $customer;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary_uom_id' => $nos->id]);
        Product::factory()->create(['code' => 'DEHUSKED-COCONUT', 'name' => 'Dehusked Coconut', 'primary_uom_id' => $nos->id]);
        $this->husk = Product::factory()->create(['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary_uom_id' => $nos->id]);

        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            Product::factory()->create(['code' => $code, 'primary_uom_id' => $kg->id]);
        }

        Product::factory()->create(['code' => 'COPRA', 'name' => 'Copra', 'primary_uom_id' => $kg->id]);
        Product::factory()->create(['code' => 'RAW-SHELL', 'name' => 'Raw Coconut Shell', 'primary_uom_id' => $nos->id, 'secondary_uom_id' => $kg->id]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->supplier = Supplier::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->seedProductionRecipes();

        $this->worker = User::factory()->admin()->create(['name' => 'Field Worker']);
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(string $quantity = '1000', string $date = '2026-09-17'): array
    {
        return [
            'receipt_date' => $date,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'transport_cost' => '0',
            'loading_cost' => '0',
            'other_cost' => '0',
            'recorded_at' => '2026-09-17T03:15:00Z',
            'lines' => [['product_id' => $this->coconut->id, 'quantity' => $quantity, 'unit_price' => '100']],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function send(string $route, string $key, array $body, ?User $as = null): TestResponse
    {
        Sanctum::actingAs($as ?? $this->worker, ['mobile-api']);

        return $this->postJson(route($route), $body, ['Idempotency-Key' => $key]);
    }

    private function stock(Product $product): string
    {
        return app(StockQueryService::class)->quantityForProduct($product->id);
    }

    private function lotOf(Product $product): InventoryLot
    {
        return InventoryLot::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
    }

    #[Test]
    public function an_offline_receipt_is_saved_and_posted_on_its_own_date_and_traced_to_the_device(): void
    {
        $key = (string) Str::uuid();
        $token = $this->worker->createToken('Chathu phone', ['mobile-api'])->plainTextToken;

        $this->withToken($token)
            ->postJson(route('api.v1.goods-receipts.record'), $this->receipt(), ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.status.value', 'POSTED')
            ->assertJsonPath('data.receipt_date', '2026-09-17');

        $receipt = GoodsReceipt::query()->sole();
        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->status);
        $this->assertSame('1000.000', $this->stock($this->coconut));

        $submission = ApiSubmission::query()->sole();
        $this->assertSame($key, $submission->idempotency_key);
        $this->assertSame($this->worker->id, $submission->user_id);
        $this->assertSame('Chathu phone', $submission->device_name);
        $this->assertNotNull($submission->personal_access_token_id);
        $this->assertSame('goods-receipts', $submission->operation);
        $this->assertTrue($submission->document->is($receipt));
        $this->assertSame('2026-09-17 03:15:00', $submission->recorded_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_same_entry_sent_again_is_replayed_and_never_recorded_twice(): void
    {
        $key = (string) Str::uuid();

        $first = $this->send('api.v1.goods-receipts.record', $key, $this->receipt())->assertCreated();

        // Even after the product was deactivated — the entry is already safely recorded.
        $this->coconut->update(['is_active' => false]);

        $this->send('api.v1.goods-receipts.record', $key, $this->receipt())
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.grn_number', $first->json('data.grn_number'));

        $this->assertSame(1, GoodsReceipt::count());
        $this->assertSame(1, ApiSubmission::count());
        $this->assertSame('1000.000', $this->stock($this->coconut));
    }

    #[Test]
    public function a_key_reused_for_a_different_entry_job_or_person_is_refused(): void
    {
        $key = (string) Str::uuid();
        $this->send('api.v1.goods-receipts.record', $key, $this->receipt())->assertCreated();

        $this->send('api.v1.goods-receipts.record', $key, $this->receipt('999'))
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->send('api.v1.goods-receipts.record', $key, $this->receipt(), User::factory()->admin()->create())
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->send('api.v1.dehusking.record', $key, $this->receipt())
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(1, GoodsReceipt::count());
        $this->assertSame('1000.000', $this->stock($this->coconut));
    }

    #[Test]
    public function an_entry_needs_a_uuid_key(): void
    {
        Sanctum::actingAs($this->worker, ['mobile-api']);

        $this->postJson(route('api.v1.goods-receipts.record'), $this->receipt())
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->postJson(route('api.v1.goods-receipts.record'), $this->receipt(), ['Idempotency-Key' => 'entry-1'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(0, GoodsReceipt::count());
    }

    #[Test]
    public function an_entry_needs_both_the_create_and_the_post_permission(): void
    {
        $postOnly = User::factory()->withPermissions(Permission::PurchasingGrnPost)->create();
        $createOnly = User::factory()->withPermissions(Permission::PurchasingGrnCreate)->create();

        $this->send('api.v1.goods-receipts.record', (string) Str::uuid(), $this->receipt(), $postOnly)->assertForbidden();
        $this->send('api.v1.goods-receipts.record', (string) Str::uuid(), $this->receipt(), $createOnly)->assertForbidden();

        $this->assertSame(0, GoodsReceipt::count());
        $this->assertSame(0, ApiSubmission::count());
    }

    #[Test]
    public function a_refused_dispatch_leaves_nothing_behind_and_can_be_corrected_under_the_same_key(): void
    {
        $this->send('api.v1.goods-receipts.record', (string) Str::uuid(), $this->receipt('100'))->assertCreated();

        $key = (string) Str::uuid();
        $dispatch = fn (string $quantity): array => [
            'delivery_date' => '2026-09-18',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'lines' => [[
                'product_id' => $this->coconut->id,
                'lot_id' => $this->lotOf($this->coconut)->id,
                'quantity' => $quantity,
                'pricing_basis' => 'PRIMARY',
                'unit_price' => '130',
            ]],
        ];

        // Only 100 in stock: the whole entry is refused — no draft, no key.
        $this->send('api.v1.deliveries.record', $key, $dispatch('150'))->assertUnprocessable();
        $this->assertSame(0, Delivery::count());
        $this->assertSame(1, ApiSubmission::count());

        $this->send('api.v1.deliveries.record', $key, $dispatch('60'))
            ->assertCreated()
            ->assertJsonPath('data.status.value', 'POSTED');

        $this->assertSame(DeliveryStatus::Posted, Delivery::query()->sole()->status);
        $this->assertSame('40.000', $this->stock($this->coconut));
    }

    #[Test]
    public function an_offline_dehusking_run_is_posted_once(): void
    {
        $this->send('api.v1.goods-receipts.record', (string) Str::uuid(), $this->receipt())->assertCreated();

        $key = (string) Str::uuid();
        $run = [
            'production_date' => '2026-09-18',
            'warehouse_id' => $this->main->id,
            'lot_id' => $this->lotOf($this->coconut)->id,
            'quantity' => '400',
            'labour_rate' => '3',
        ];

        $this->send('api.v1.dehusking.record', $key, $run)->assertCreated()->assertJsonPath('data.status.value', 'POSTED');
        $this->send('api.v1.dehusking.record', $key, $run)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(ProductionStatus::Posted, ProductionOrder::query()->sole()->status);
        $this->assertSame('600.000', $this->stock($this->coconut));
        $this->assertSame('400.000', $this->stock($this->husk));
    }

    #[Test]
    public function a_copy_that_loses_the_race_rolls_back_its_document_and_is_told_to_send_again(): void
    {
        $key = (string) Str::uuid();
        $this->send('api.v1.goods-receipts.record', $key, $this->receipt())->assertCreated();

        // The second copy got past the replay check before the first one committed.
        $entry = new SubmittedEntry($key, 'goods-receipts', ApiSubmission::hashOf($this->receipt()), $this->worker->id, null, null, null);

        try {
            app(RecordApiSubmission::class)->handle($entry, fn (): GoodsReceipt => app(RecordKioskEntry::class)->receive(
                collect($this->receipt())->except('recorded_at')->all(),
                $this->worker->id,
            ));
            $this->fail('The duplicate was recorded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(1, GoodsReceipt::count());
        $this->assertSame('1000.000', $this->stock($this->coconut));
    }

    #[Test]
    public function a_submission_is_permanent_audit_history(): void
    {
        $this->send('api.v1.goods-receipts.record', (string) Str::uuid(), $this->receipt())->assertCreated();
        $submission = ApiSubmission::query()->sole();

        $this->assertThrows(fn () => $submission->update(['device_name' => 'Other']), RuntimeException::class);
        $this->assertThrows(fn () => $submission->delete(), RuntimeException::class);
    }
}
