<?php

namespace Tests\Feature\Sales;

use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Production\PostHuskChippingProduction;
use App\Actions\Sales\PostDelivery;
use App\Actions\Sales\ReverseDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\DeliveryStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionType;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Raw-material backflushing at delivery: a chipping output's delivery states the raw husks it
 * used, and posting takes them from the pool at their weighted average into cost of goods
 * sold — atomically with the sale. See docs/BUSINESS_RULES.md §19.
 */
class DeliveryBackflushTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $main;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->customer = Customer::factory()->create();
        $this->seedProductionRecipes();
        $this->actingAs(User::factory()->admin()->create());
    }

    private function product(string $code): Product
    {
        return Product::query()->where('code', $code)->firstOrFail();
    }

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    /** Raw husk into the pool at a known cost. */
    private function husk(string $quantity, string $unitCost, ?Warehouse $warehouse = null): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->product('RAW-HUSK')->id,
                warehouseId: ($warehouse ?? $this->main)->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                unitCost: $unitCost,
            ),
        ]);
    }

    /** Chipping outputs into stock, their husk cost pending. */
    private function chip(array $outputs): void
    {
        $order = ProductionOrder::factory()->create([
            'production_type' => ProductionType::HuskChipping,
            'warehouse_id' => $this->main->id,
        ]);

        foreach ($outputs as $code => $quantity) {
            ProductionOutput::factory()->create([
                'production_order_id' => $order->id,
                'product_id' => $this->product($code)->id,
                'quantity' => $quantity,
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }

        app(PostHuskChippingProduction::class)->handle($order);
    }

    private function draft(string $code, string $quantity, ?string $husks): Delivery
    {
        $delivery = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
        ]);

        DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id,
            'product_id' => $this->product($code)->id,
            'lot_id' => null,
            'quantity' => $quantity,
            'unit_price' => '100.000000',
            'backflush_quantity' => $husks,
        ]);

        return $delivery->refresh();
    }

    #[Test]
    public function a_chip_delivery_takes_its_husks_from_the_pool_at_the_average_cost(): void
    {
        $this->husk('400.000', '10.000000');
        $this->husk('200.000', '13.000000');
        $this->chip(['HUSK-9C' => '50.000']);

        $delivery = app(PostDelivery::class)->handle($this->draft('HUSK-9C', '20.000', '180.000'));
        $line = $delivery->lines->first();

        // (4,000 + 2,600) / 600 = 11.00; 180 x 11.00 = 1,980.
        $this->assertSame($this->product('RAW-HUSK')->id, $line->backflush_product_id);
        $this->assertSame('1980.000000', $line->backflush_cost);
        $this->assertSame('1980.000000', $line->cost_of_goods_sold);
        $this->assertSame('420.000', $this->stock()->quantityForProduct($this->product('RAW-HUSK')->id));
        $this->assertSame('11.000000', $this->stock()->weightedAverageCostForProduct($this->product('RAW-HUSK')->id));

        $backflush = $delivery->backflushTransaction;
        $this->assertSame(InventoryTransactionType::BackflushIssue, $backflush->transaction_type);
        $this->assertSame(Delivery::class, $backflush->source_type);
        $this->assertCount(1, $backflush->lines);
        $this->assertNull($backflush->lines->first()->lot_id);
    }

    #[Test]
    public function a_litre_stock_item_backflushes_like_its_kilogram_twin_and_stays_separate(): void
    {
        $this->husk('300.000', '10.000000');
        $this->chip(['HUSK-10C' => '50.000', 'HUSK-10C-L' => '80.000']);

        app(PostDelivery::class)->handle($this->draft('HUSK-10C-L', '30.000', '90.000'));

        $this->assertSame('50.000', $this->stock()->quantityForProduct($this->product('HUSK-10C')->id));
        $this->assertSame('50.000', $this->stock()->quantityForProduct($this->product('HUSK-10C-L')->id));
        $this->assertSame('210.000', $this->stock()->quantityForProduct($this->product('RAW-HUSK')->id));
    }

    #[Test]
    public function the_husks_used_are_required_for_a_chipping_output(): void
    {
        $this->husk('100.000', '10.000000');
        $this->chip(['HUSK-7C' => '10.000']);

        foreach ([null, '0.000'] as $husks) {
            try {
                app(PostDelivery::class)->handle($this->draft('HUSK-7C', '5.000', $husks));
                $this->fail('Expected a chip delivery without husks to be refused.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines.0.backflush_quantity', $e->errors());
            }
        }

        $this->assertSame('10.000', $this->stock()->quantityForProduct($this->product('HUSK-7C')->id));
    }

    #[Test]
    public function a_product_that_is_not_a_chipping_output_may_not_state_husks(): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->product('COCONUT')->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: '100.000',
                unitCost: '50.000000',
            ),
        ]);
        $this->husk('100.000', '10.000000');

        $this->expectException(ValidationException::class);

        app(PostDelivery::class)->handle($this->draft('COCONUT', '10.000', '5.000'));
    }

    #[Test]
    public function not_enough_husk_refuses_the_whole_delivery(): void
    {
        $this->husk('100.000', '10.000000');
        $this->chip(['HUSK-7C' => '10.000']);
        $draft = $this->draft('HUSK-7C', '5.000', '101.000');
        $transactions = InventoryTransaction::count();

        try {
            app(PostDelivery::class)->handle($draft);
            $this->fail('Expected the delivery to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.backflush_quantity', $e->errors());
        }

        $this->assertSame($transactions, InventoryTransaction::count());
        $this->assertSame(DeliveryStatus::Draft, $draft->fresh()->status);
        $this->assertSame('10.000', $this->stock()->quantityForProduct($this->product('HUSK-7C')->id));
    }

    #[Test]
    public function the_husk_must_be_in_the_delivering_warehouse(): void
    {
        $store = Warehouse::factory()->create(['code' => 'STORE']);
        $this->husk('500.000', '10.000000', $store);
        $this->chip(['HUSK-7C' => '10.000']);

        $this->expectException(ValidationException::class);

        app(PostDelivery::class)->handle($this->draft('HUSK-7C', '5.000', '10.000'));
    }

    #[Test]
    public function reversing_the_delivery_returns_the_husks_at_their_original_cost(): void
    {
        $this->husk('200.000', '10.000000');
        $this->chip(['HUSK-7C' => '10.000']);
        $delivery = app(PostDelivery::class)->handle($this->draft('HUSK-7C', '5.000', '50.000'));

        // A later purchase moves the average, so a re-costed reversal would be visible.
        $this->husk('150.000', '20.000000');

        $reversed = app(ReverseDelivery::class)->handle($delivery);

        $this->assertSame(DeliveryStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->backflush_reversal_transaction_id);

        // 150 left at 10 + 150 at 20, then 50 back at 10: 350 holding 1,500 + 3,000 + 500.
        $husk = $this->product('RAW-HUSK')->id;
        $this->assertSame('350.000', $this->stock()->quantityForProduct($husk));
        $this->assertSame('5000.000000', $this->stock()->valueForProduct($husk));
        $this->assertSame('10.000', $this->stock()->quantityForProduct($this->product('HUSK-7C')->id));
    }

    #[Test]
    public function the_delivery_screen_offers_and_requires_raw_husks_used(): void
    {
        $this->husk('100.000', '10.000000');
        $this->chip(['HUSK-7C' => '10.000']);

        $this->get(route('deliveries.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('products', fn ($products) => collect($products)
                    ->firstWhere('code', 'HUSK-7C')['backflush']['code'] === 'RAW-HUSK'
                    && collect($products)->firstWhere('code', 'COPRA')['backflush'] === null
                    && collect($products)->firstWhere('code', 'RAW-HUSK')['tracks_lots'] === false));

        $payload = fn (?string $husks, string $code = 'HUSK-7C') => [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => '2026-09-20',
            'lines' => [[
                'product_id' => $this->product($code)->id,
                'quantity' => '5',
                'pricing_basis' => 'PRIMARY',
                'unit_price' => '100',
                'backflush_quantity' => $husks,
            ]],
        ];

        $this->post(route('deliveries.store'), $payload(null))->assertSessionHasErrors('lines.0.backflush_quantity');
        $this->post(route('deliveries.store'), $payload('2.5'))->assertSessionHasErrors('lines.0.backflush_quantity');
        $this->post(route('deliveries.store'), $payload('5', 'COPRA'))->assertSessionHasErrors('lines.0.backflush_quantity');

        $this->post(route('deliveries.store'), $payload('45'))->assertSessionHasNoErrors();

        $line = DeliveryLine::query()->latest('id')->firstOrFail();
        $this->assertSame('45.000', $line->backflush_quantity);
        // Nothing moves until it is posted.
        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->product('RAW-HUSK')->id));

        $this->patch(route('deliveries.post', $line->delivery_id))->assertSessionHasNoErrors();

        $this->assertSame('55.000', $this->stock()->quantityForProduct($this->product('RAW-HUSK')->id));
        $this->get(route('deliveries.show', $line->delivery_id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('delivery.lines.0.backflush_quantity', '45.000')
                ->where('delivery.lines.0.backflush_cost', '450.000000')
                ->where('delivery.lines.0.backflush_code', 'RAW-HUSK'));
    }
}
