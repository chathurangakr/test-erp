<?php

namespace Tests\Feature\Sales;

use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Sales\ApproveSalesOrder;
use App\Actions\Sales\CancelDelivery;
use App\Actions\Sales\CancelSalesOrder;
use App\Actions\Sales\GenerateSalesReference;
use App\Actions\Sales\PostDelivery;
use App\Actions\Sales\ReverseDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\DeliveryStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\PricingBasis;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    private UnitOfMeasure $nos;

    private UnitOfMeasure $kg;

    private Product $coconut;

    private Product $shell;

    private Customer $customer;

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->coconut = Product::factory()->create([
            'code' => 'COCONUT',
            'name' => 'Whole Coconut',
            'primary_uom_id' => $this->nos->id,
        ]);

        $this->shell = Product::factory()->create([
            'code' => 'RAW-SHELL',
            'name' => 'Raw Coconut Shell',
            'primary_uom_id' => $this->nos->id,
            'secondary_uom_id' => $this->kg->id,
        ]);

        $this->customer = Customer::factory()->create(['code' => 'CUS-001']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
    }

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    /**
     * Put stock on hand at a known cost, without going through purchasing.
     */
    private function receive(
        string $quantity,
        string $unitCost,
        ?Product $product = null,
        ?InventoryLot $lot = null,
        ?string $secondary = null,
    ): void {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: ($product ?? $this->coconut)->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lot?->id,
                secondaryQuantity: $secondary,
                unitCost: $unitCost,
            ),
        ]);
    }

    private function lotFor(Product $product): InventoryLot
    {
        return app(CreateInventoryLot::class)->handle($product);
    }

    private function draftOrder(array $lines = [], array $attributes = []): SalesOrder
    {
        $order = SalesOrder::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
        ], $attributes));

        foreach ($lines ?: [['product' => $this->coconut, 'quantity' => '10000.000', 'price' => '130.000000']] as $line) {
            SalesOrderLine::factory()->create([
                'sales_order_id' => $order->id,
                'product_id' => $line['product']->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['price'],
                'line_total' => bcmul($line['quantity'], $line['price'], 6),
            ]);
        }

        return $order->refresh();
    }

    private function draftDelivery(array $lines, array $attributes = []): Delivery
    {
        $delivery = Delivery::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
        ], $attributes));

        foreach ($lines as $line) {
            DeliveryLine::factory()->create([
                'delivery_id' => $delivery->id,
                'product_id' => $line['product']->id,
                'lot_id' => $line['lot']->id ?? null,
                'quantity' => $line['quantity'],
                'secondary_quantity' => $line['secondary'] ?? null,
                'pricing_basis' => $line['basis'] ?? PricingBasis::Primary,
                'unit_price' => $line['price'],
                'sales_order_line_id' => $line['so_line_id'] ?? null,
            ]);
        }

        return $delivery->refresh();
    }

    private function deliver(Delivery $delivery): Delivery
    {
        return app(PostDelivery::class)->handle($delivery);
    }

    // ------------------------------------------------------------- sales orders

    #[Test]
    public function a_draft_sales_order_can_be_created(): void
    {
        $order = $this->draftOrder();

        $this->assertSame(SalesOrderStatus::Draft, $order->status);
        $this->assertSame('1300000.000000', $order->salesTotal());
    }

    #[Test]
    public function a_sales_order_can_be_approved(): void
    {
        $approved = app(ApproveSalesOrder::class)->handle($this->draftOrder());

        $this->assertSame(SalesOrderStatus::Approved, $approved->status);
        $this->assertNotNull($approved->approved_at);
    }

    #[Test]
    public function an_approved_sales_order_is_no_longer_freely_editable(): void
    {
        $order = app(ApproveSalesOrder::class)->handle($this->draftOrder());

        $this->assertFalse($order->status->isEditable());

        $this->expectException(ValidationException::class);
        app(ApproveSalesOrder::class)->handle($order);
    }

    #[Test]
    public function an_empty_sales_order_cannot_be_approved(): void
    {
        $order = SalesOrder::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
        ]);

        $this->expectException(ValidationException::class);

        app(ApproveSalesOrder::class)->handle($order);
    }

    #[Test]
    public function a_cancelled_sales_order_cannot_be_delivered_against(): void
    {
        $this->receive('10000.000', '102.500000');

        $order = app(ApproveSalesOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();
        app(CancelSalesOrder::class)->handle($order);

        $delivery = $this->draftDelivery(
            [['product' => $this->coconut, 'quantity' => '10.000', 'price' => '130.000000', 'so_line_id' => $line->id]],
            ['sales_order_id' => $order->id],
        );

        $this->expectException(ValidationException::class);

        $this->deliver($delivery);
    }

    // ------------------------------------------------------------- deliveries

    #[Test]
    public function a_direct_delivery_without_a_sales_order_issues_stock(): void
    {
        $this->receive('1000.000', '102.500000');

        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '400.000', 'price' => '130.000000'],
        ]));

        $this->assertSame(DeliveryStatus::Posted, $posted->status);
        $this->assertNull($posted->sales_order_id);
        $this->assertSame('600.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function a_draft_delivery_creates_no_stock_movement(): void
    {
        $this->receive('1000.000', '102.500000');
        $before = InventoryTransaction::count();

        $this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '400.000', 'price' => '130.000000'],
        ]);

        $this->assertSame($before, InventoryTransaction::count());
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function posting_a_delivery_creates_a_sales_issue_transaction_and_decreases_stock(): void
    {
        $this->receive('10000.000', '102.500000');

        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '2000.000', 'price' => '130.000000'],
        ]));

        $transaction = $posted->inventoryTransaction;

        $this->assertSame(InventoryTransactionType::SalesIssue, $transaction->transaction_type);
        $this->assertSame(Delivery::class, $transaction->source_type);
        $this->assertSame(InventoryDirection::Out, $transaction->lines->first()->direction);
        $this->assertSame('8000.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function a_delivery_is_costed_at_the_current_weighted_average(): void
    {
        // The worked example: 2,000 coconut sold at 130 against a WAC of 102.50.
        $this->receive('10000.000', '102.500000');

        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '2000.000', 'price' => '130.000000'],
        ]));

        $line = $posted->lines->first();

        $this->assertSame('260000.000000', $line->sales_value);
        $this->assertSame('102.500000', $line->inventory_unit_cost);
        $this->assertSame('205000.000000', $line->cost_of_goods_sold);
        $this->assertSame('55000.000000', $line->grossProfit());
        $this->assertSame('21.15', $line->grossMarginPercent());
    }

    #[Test]
    public function the_selling_price_does_not_affect_the_weighted_average_cost(): void
    {
        $this->receive('1000.000', '102.500000');
        $this->assertSame('102.500000', $this->stock()->weightedAverageCostForProduct($this->coconut->id));

        // Sell far above cost — the average must not move towards the selling price.
        $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '500.000', 'price' => '900.000000'],
        ]));

        $this->assertSame('102.500000', $this->stock()->weightedAverageCostForProduct($this->coconut->id));
        $this->assertSame('500.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('51250.000000', $this->stock()->valueForProduct($this->coconut->id));
    }

    #[Test]
    public function a_zero_sales_value_yields_a_zero_margin_rather_than_a_division_error(): void
    {
        $this->receive('100.000', '10.000000');

        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '0.000000'],
        ]));

        $line = $posted->lines->first();

        $this->assertSame('0.000000', $line->sales_value);
        $this->assertSame('-100.000000', $line->grossProfit());
        $this->assertSame('0.00', $line->grossMarginPercent());
        $this->assertSame('0.00', $posted->grossMarginPercent());
    }

    #[Test]
    public function insufficient_stock_is_rejected(): void
    {
        $this->receive('100.000', '10.000000');

        try {
            $this->deliver($this->draftDelivery([
                ['product' => $this->coconut, 'quantity' => '101.000', 'price' => '130.000000'],
            ]));
            $this->fail('Expected insufficient stock to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function an_inactive_customer_is_rejected(): void
    {
        $this->receive('100.000', '10.000000');
        $this->customer->update(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '130.000000'],
        ]));
    }

    #[Test]
    public function an_inactive_warehouse_is_rejected(): void
    {
        $this->receive('100.000', '10.000000');
        $inactive = Warehouse::factory()->inactive()->create(['code' => 'WH-OFF']);

        $this->expectException(ValidationException::class);

        $this->deliver($this->draftDelivery(
            [['product' => $this->coconut, 'quantity' => '10.000', 'price' => '130.000000']],
            ['warehouse_id' => $inactive->id],
        ));
    }

    #[Test]
    public function an_inactive_product_with_existing_stock_may_still_be_delivered(): void
    {
        $this->receive('100.000', '10.000000');
        $this->coconut->update(['is_active' => false]);

        // Deactivating a product stops new purchases, not the clearing of what is left.
        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '40.000', 'price' => '130.000000'],
        ]));

        $this->assertSame(DeliveryStatus::Posted, $posted->status);
        $this->assertSame('60.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function a_zero_quantity_line_is_rejected(): void
    {
        $this->receive('100.000', '10.000000');

        $this->expectException(ValidationException::class);

        $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '0.000', 'price' => '130.000000'],
        ]));
    }

    // ------------------------------------------------------------- sales order linkage

    #[Test]
    public function a_partial_delivery_moves_the_order_to_partially_delivered(): void
    {
        $this->receive('10000.000', '102.500000');

        $order = app(ApproveSalesOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();

        $this->deliver($this->draftDelivery(
            [['product' => $this->coconut, 'quantity' => '4000.000', 'price' => '130.000000', 'so_line_id' => $line->id]],
            ['sales_order_id' => $order->id],
        ));

        $this->assertSame('4000.000', $line->fresh()->delivered_quantity);
        $this->assertSame('6000.000', $line->fresh()->outstandingQuantity());
        $this->assertSame(SalesOrderStatus::PartiallyDelivered, $order->fresh()->status);
    }

    #[Test]
    public function a_second_delivery_completes_the_order(): void
    {
        $this->receive('10000.000', '102.500000');

        $order = app(ApproveSalesOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();

        foreach (['4000.000', '6000.000'] as $quantity) {
            $this->deliver($this->draftDelivery(
                [['product' => $this->coconut, 'quantity' => $quantity, 'price' => '130.000000', 'so_line_id' => $line->id]],
                ['sales_order_id' => $order->id],
            ));
        }

        $this->assertSame('10000.000', $line->fresh()->delivered_quantity);
        $this->assertSame('0.000', $line->fresh()->outstandingQuantity());
        $this->assertSame(SalesOrderStatus::Delivered, $order->fresh()->status);
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function delivering_more_than_ordered_is_rejected(): void
    {
        $this->receive('20000.000', '102.500000');

        $order = app(ApproveSalesOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();

        $this->deliver($this->draftDelivery(
            [['product' => $this->coconut, 'quantity' => '10000.000', 'price' => '130.000000', 'so_line_id' => $line->id]],
            ['sales_order_id' => $order->id],
        ));

        // One more unit than ordered must fail.
        $over = $this->draftDelivery(
            [['product' => $this->coconut, 'quantity' => '1.000', 'price' => '130.000000', 'so_line_id' => $line->id]],
            ['sales_order_id' => $order->id],
        );

        try {
            $this->deliver($over);
            $this->fail('Expected over-delivery to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('10000.000', $line->fresh()->delivered_quantity);
        $this->assertSame(SalesOrderStatus::Delivered, $order->fresh()->status);
    }

    // ------------------------------------------------------------- lots

    #[Test]
    public function a_lot_must_be_selected_when_the_product_is_held_in_lots(): void
    {
        $first = $this->lotFor($this->coconut);
        $second = $this->lotFor($this->coconut);

        $this->receive('100.000', '10.000000', lot: $first);
        $this->receive('100.000', '20.000000', lot: $second);

        // Two lots hold stock, so the system must not silently pick one.
        $this->expectException(ValidationException::class);

        $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '130.000000'],
        ]));
    }

    #[Test]
    public function a_lot_belonging_to_another_product_is_rejected(): void
    {
        $shellLot = $this->lotFor($this->shell);
        $this->receive('100.000', '10.000000');

        $this->expectException(ValidationException::class);

        $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '130.000000', 'lot' => $shellLot],
        ]));
    }

    #[Test]
    public function issuing_more_than_a_lot_holds_is_rejected(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('5.000', '10.000000', lot: $lot);
        // Plenty of unlotted stock exists, but the lot itself only holds 5.
        $this->receive('500.000', '10.000000');

        try {
            $this->deliver($this->draftDelivery([
                ['product' => $this->coconut, 'quantity' => '10.000', 'price' => '130.000000', 'lot' => $lot],
            ]));
            $this->fail('Expected the lot shortage to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('5.000', $this->stock()->quantityForLot($lot->id));
    }

    #[Test]
    public function a_delivery_from_a_named_lot_reduces_that_lot(): void
    {
        $lot = $this->lotFor($this->coconut);
        $this->receive('100.000', '10.000000', lot: $lot);

        $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '30.000', 'price' => '130.000000', 'lot' => $lot],
        ]));

        $this->assertSame('70.000', $this->stock()->quantityForLot($lot->id));
    }

    // ------------------------------------------------------------- dual UOM shell

    #[Test]
    public function shell_records_both_a_count_and_a_weight(): void
    {
        $this->receive('2000.000', '50.000000', product: $this->shell, secondary: '840.000');

        $posted = $this->deliver($this->draftDelivery([
            [
                'product' => $this->shell,
                'quantity' => '500.000',
                'secondary' => '208.750',
                'price' => '100.000000',
            ],
        ]));

        $line = $posted->lines->first();

        $this->assertSame('500.000', $line->quantity);
        $this->assertSame('208.750', $line->secondary_quantity);
        $this->assertSame('1500.000', $this->stock()->quantityForProduct($this->shell->id));
    }

    #[Test]
    public function shell_applies_no_fixed_conversion_between_count_and_weight(): void
    {
        $this->receive('4000.000', '50.000000', product: $this->shell, secondary: '1680.000');

        // Two deliveries of the same count but genuinely different weights.
        $this->deliver($this->draftDelivery([
            ['product' => $this->shell, 'quantity' => '500.000', 'secondary' => '208.750', 'price' => '100.000000'],
        ]));
        $this->deliver($this->draftDelivery([
            ['product' => $this->shell, 'quantity' => '500.000', 'secondary' => '191.400', 'price' => '100.000000'],
        ]));

        $weights = DeliveryLine::where('product_id', $this->shell->id)->pluck('secondary_quantity')->all();

        $this->assertSame(['208.750', '191.400'], $weights);
    }

    #[Test]
    public function shell_can_be_priced_on_its_primary_count(): void
    {
        $this->receive('2000.000', '50.000000', product: $this->shell, secondary: '840.000');

        $posted = $this->deliver($this->draftDelivery([
            [
                'product' => $this->shell,
                'quantity' => '500.000',
                'secondary' => '208.750',
                'basis' => PricingBasis::Primary,
                'price' => '60.000000',
            ],
        ]));

        $line = $posted->lines->first();

        // Priced on 500 NOS, not on the weight.
        $this->assertSame('30000.000000', $line->sales_value);
        $this->assertSame('50.000000', $line->inventory_unit_cost);
        $this->assertSame('25000.000000', $line->cost_of_goods_sold);
    }

    #[Test]
    public function shell_can_be_priced_on_its_secondary_weight(): void
    {
        $this->receive('2000.000', '50.000000', product: $this->shell, secondary: '840.000');

        $posted = $this->deliver($this->draftDelivery([
            [
                'product' => $this->shell,
                'quantity' => '500.000',
                'secondary' => '208.750',
                'basis' => PricingBasis::Secondary,
                'price' => '80.000000',
            ],
        ]));

        $line = $posted->lines->first();

        // Revenue follows the weight: 208.750 x 80 = 16,700.
        $this->assertSame('16700.000000', $line->sales_value);

        // Cost still follows the COUNT, because that is what left stock.
        $this->assertSame('50.000000', $line->inventory_unit_cost);
        $this->assertSame('25000.000000', $line->cost_of_goods_sold);
        $this->assertSame('-8300.000000', $line->grossProfit());
    }

    #[Test]
    public function a_product_without_a_secondary_unit_cannot_be_priced_on_the_secondary_basis(): void
    {
        $this->receive('100.000', '10.000000');

        $this->expectException(ValidationException::class);

        $this->deliver($this->draftDelivery([
            [
                'product' => $this->coconut,
                'quantity' => '10.000',
                'basis' => PricingBasis::Secondary,
                'price' => '80.000000',
            ],
        ]));
    }

    #[Test]
    public function secondary_pricing_requires_a_secondary_quantity(): void
    {
        $this->receive('2000.000', '50.000000', product: $this->shell, secondary: '840.000');

        $this->expectException(ValidationException::class);

        $this->deliver($this->draftDelivery([
            [
                'product' => $this->shell,
                'quantity' => '500.000',
                'basis' => PricingBasis::Secondary,
                'price' => '80.000000',
            ],
        ]));
    }

    // ------------------------------------------------------------- atomicity

    #[Test]
    public function a_failed_posting_changes_nothing(): void
    {
        $this->receive('10000.000', '102.500000');

        $order = app(ApproveSalesOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();

        $transactionsBefore = InventoryTransaction::count();

        // First line is deliverable; the second exceeds available stock.
        $delivery = $this->draftDelivery(
            [
                ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '130.000000', 'so_line_id' => $line->id],
                ['product' => $this->coconut, 'quantity' => '999999.000', 'price' => '130.000000'],
            ],
            ['sales_order_id' => $order->id],
        );

        try {
            $this->deliver($delivery);
            $this->fail('Expected the posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame($transactionsBefore, InventoryTransaction::count());
        $this->assertSame('10000.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('0.000', $line->fresh()->delivered_quantity);
        $this->assertSame(SalesOrderStatus::Approved, $order->fresh()->status);
        $this->assertSame(DeliveryStatus::Draft, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->lines->first()->cost_of_goods_sold);
    }

    #[Test]
    public function a_delivery_cannot_be_posted_twice(): void
    {
        $this->receive('1000.000', '102.500000');

        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '130.000000'],
        ]));

        try {
            $this->deliver($posted);
            $this->fail('Expected double posting to be rejected.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame('900.000', $this->stock()->quantityForProduct($this->coconut->id));
    }

    #[Test]
    public function a_posted_delivery_is_immutable(): void
    {
        $this->receive('1000.000', '102.500000');

        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '130.000000'],
        ]));

        try {
            $posted->update(['notes' => 'tampered']);
            $this->fail('Expected the update to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $posted->delete();
            $this->fail('Expected the delete to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only a draft', $e->getMessage());
        }

        $this->assertDatabaseHas('deliveries', ['id' => $posted->id, 'notes' => null]);
    }

    // ------------------------------------------------------------- reversal

    #[Test]
    public function reversing_a_delivery_restores_stock_and_value(): void
    {
        $this->receive('1000.000', '102.500000');

        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '400.000', 'price' => '130.000000'],
        ]));

        $reversed = app(ReverseDelivery::class)->handle($posted);

        $this->assertSame(DeliveryStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversal_inventory_transaction_id);

        $reversal = $reversed->reversalInventoryTransaction;
        $this->assertSame(InventoryTransactionType::Reversal, $reversal->transaction_type);
        $this->assertSame(InventoryDirection::In, $reversal->lines->first()->direction);

        // Stock, value and average all back where they started.
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('102500.000000', $this->stock()->valueForProduct($this->coconut->id));
        $this->assertSame('102.500000', $this->stock()->weightedAverageCostForProduct($this->coconut->id));
    }

    #[Test]
    public function a_reversal_returns_stock_at_the_original_cost_not_the_current_average(): void
    {
        $this->receive('1000.000', '100.000000');

        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '500.000', 'price' => '130.000000'],
        ]));
        $this->assertSame('100.000000', $posted->lines->first()->inventory_unit_cost);

        // Buy more at a very different price, moving the average.
        $this->receive('500.000', '300.000000');
        $this->assertSame('200.000000', $this->stock()->weightedAverageCostForProduct($this->coconut->id));

        $reversed = app(ReverseDelivery::class)->handle($posted);
        $reversalLine = $reversed->reversalInventoryTransaction->lines->first();

        // Stock returns at the 100.00 it left at, not the current 200.00.
        $this->assertSame('100.000000', $reversalLine->unit_cost);

        // 1,000 received, 500 sold, 500 more received, then the sale un-done:
        // 500 + 500 + 500 = 1,500 on hand, valued 50,000 + 150,000 + 50,000 = 250,000.
        $this->assertSame('1500.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertSame('250000.000000', $this->stock()->valueForProduct($this->coconut->id));

        // Had the reversal used the current 200.00 average it would have returned 100,000
        // of value for goods that left carrying only 50,000 — inventing 50,000 out of air.
        $this->assertSame('166.666667', $this->stock()->weightedAverageCostForProduct($this->coconut->id));
    }

    #[Test]
    public function reversing_a_delivery_restores_the_sales_order_quantity(): void
    {
        $this->receive('10000.000', '102.500000');

        $order = app(ApproveSalesOrder::class)->handle($this->draftOrder());
        $line = $order->lines->first();

        $posted = $this->deliver($this->draftDelivery(
            [['product' => $this->coconut, 'quantity' => '10000.000', 'price' => '130.000000', 'so_line_id' => $line->id]],
            ['sales_order_id' => $order->id],
        ));
        $this->assertSame(SalesOrderStatus::Delivered, $order->fresh()->status);

        app(ReverseDelivery::class)->handle($posted);

        $this->assertSame('0.000', $line->fresh()->delivered_quantity);
        $this->assertSame(SalesOrderStatus::Approved, $order->fresh()->status);
    }

    #[Test]
    public function a_delivery_cannot_be_reversed_twice(): void
    {
        $this->receive('1000.000', '102.500000');

        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '130.000000'],
        ]));

        app(ReverseDelivery::class)->handle($posted);

        $this->expectException(ValidationException::class);

        app(ReverseDelivery::class)->handle($posted->fresh());
    }

    // ------------------------------------------------------------- cancellation

    #[Test]
    public function a_draft_delivery_can_be_cancelled_and_creates_no_stock(): void
    {
        $this->receive('1000.000', '102.500000');

        $delivery = $this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '130.000000'],
        ]);

        $cancelled = app(CancelDelivery::class)->handle($delivery);

        $this->assertSame(DeliveryStatus::Cancelled, $cancelled->status);
        $this->assertFalse($cancelled->status->reachedInventory());
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->coconut->id));
        $this->assertDatabaseHas('deliveries', ['id' => $delivery->id]);
    }

    #[Test]
    public function a_posted_delivery_cannot_be_cancelled(): void
    {
        $this->receive('1000.000', '102.500000');

        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '130.000000'],
        ]));

        $this->expectException(ValidationException::class);

        app(CancelDelivery::class)->handle($posted);
    }

    // ------------------------------------------------------------- scope guards

    #[Test]
    public function sales_creates_no_receivable_or_general_ledger_records(): void
    {
        $this->receive('1000.000', '102.500000');

        $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '130.000000'],
        ]));

        foreach ([
            'journals', 'journal_lines', 'accounts_receivable',
            'receivables', 'customer_payments', 'payments', 'cash_transactions',
        ] as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "Sales must not create a [{$table}] table — finance is a later phase."
            );
        }

        // The accounting foundation exists (Finance Phase 1), but operational documents do
        // not generate journals yet — that integration is a later, deliberate phase.
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalEntryLine::count());

        foreach (['balance', 'receivable', 'outstanding'] as $column) {
            $this->assertFalse(Schema::hasColumn('customers', $column));
        }
    }

    #[Test]
    public function the_products_table_still_has_no_stock_column(): void
    {
        foreach (['current_stock', 'stock', 'stock_quantity', 'quantity', 'on_hand'] as $column) {
            $this->assertFalse(Schema::hasColumn('products', $column));
        }
    }

    #[Test]
    public function sales_document_numbers_follow_the_documented_format(): void
    {
        $reference = app(GenerateSalesReference::class);

        $this->assertMatchesRegularExpression('/^SO-\d{8}-\d{6}$/', $reference->forSalesOrder());
        $this->assertMatchesRegularExpression('/^DN-\d{8}-\d{6}$/', $reference->forDelivery());
    }

    #[Test]
    public function gross_profit_aggregates_across_delivery_lines(): void
    {
        $this->receive('1000.000', '100.000000');
        $this->receive('1000.000', '40.000000', product: $this->shell, secondary: '420.000');

        $posted = $this->deliver($this->draftDelivery([
            ['product' => $this->coconut, 'quantity' => '100.000', 'price' => '150.000000'],
            ['product' => $this->shell, 'quantity' => '100.000', 'secondary' => '42.000', 'price' => '50.000000'],
        ]));

        // Coconut: 15,000 revenue - 10,000 cost = 5,000
        // Shell:    5,000 revenue -  4,000 cost = 1,000
        $this->assertSame('20000.000000', $posted->salesTotal());
        $this->assertSame('14000.000000', $posted->costOfGoodsSold());
        $this->assertSame('6000.000000', $posted->grossProfit());
        $this->assertSame('30.00', $posted->grossMarginPercent());
    }
}
