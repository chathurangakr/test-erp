<?php

namespace Tests\Feature;

use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Sales\PostDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Money is rupees and cents: typed to at most two decimals, and the amounts owed between the
 * factory and others are rounded to the cent so every bill can be settled exactly. Inventory
 * costs keep their full six-decimal scale. See config('accounting.money_decimals').
 */
class MoneyPrecisionTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $main;

    private Product $copra;

    protected function setUp(): void
    {
        parent::setUp();

        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);
        $this->copra = Product::factory()->create(['code' => 'COPRA', 'primary_uom_id' => $kg->id]);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->actingAs(User::factory()->admin()->create());
    }

    #[Test]
    public function money_rounds_half_up_to_the_cent_and_keeps_the_stored_scale(): void
    {
        $this->assertSame('6044.140000', DecimalMath::money('6044.14125'));
        $this->assertSame('6044.150000', DecimalMath::money('6044.145'));
        $this->assertSame('-1.010000', DecimalMath::money('-1.005'));
    }

    #[Test]
    public function a_typed_price_may_not_hold_a_fraction_of_a_cent(): void
    {
        $customer = Customer::factory()->create();
        $payload = fn (string $price): array => [
            'customer_id' => $customer->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => '2026-09-20',
            'lines' => [[
                'product_id' => $this->copra->id,
                'quantity' => '1',
                'pricing_basis' => 'PRIMARY',
                'unit_price' => $price,
            ]],
        ];

        $this->post(route('deliveries.store'), $payload('300.335'))
            ->assertSessionHasErrors('lines.0.unit_price');

        // Whole rupees, cents, and the API's six-decimal form of a cents amount all pass.
        foreach (['300', '300.33', '300.330000'] as $price) {
            $this->post(route('deliveries.store'), $payload($price))
                ->assertSessionDoesntHaveErrors('lines.0.unit_price');
        }
    }

    #[Test]
    public function a_delivery_bills_its_sales_value_to_the_cent(): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->copra->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: '100.000',
                unitCost: '123.456789',
            ),
        ]);

        $delivery = Delivery::factory()->create(['customer_id' => Customer::factory()->create()->id, 'warehouse_id' => $this->main->id]);
        DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id,
            'product_id' => $this->copra->id,
            'quantity' => '20.125',
            'unit_price' => '300.330000',
        ]);

        $line = app(PostDelivery::class)->handle($delivery->refresh())->lines->first();

        // 20.125 x 300.33 = 6,044.14125 — billed as 6,044.14.
        $this->assertSame('6044.140000', $line->sales_value);
        // The stock cost keeps its full scale: 20.125 x 123.456789.
        $this->assertSame('2484.567879', $line->cost_of_goods_sold);
    }

    #[Test]
    public function a_goods_receipt_values_its_material_to_the_cent(): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $this->main->id,
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->copra->id,
            'quantity' => '10.125',
            'unit_price' => '401.330000',
        ]);

        $line = app(PostGoodsReceipt::class)->handle($receipt->refresh())->lines->first();

        // 10.125 x 401.33 = 4,063.46625 — what the supplier bills is 4,063.47.
        $this->assertSame('4063.470000', $line->material_cost);
    }
}
