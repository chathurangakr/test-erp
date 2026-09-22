<?php

namespace Tests\Feature\Finance;

use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Production\PostCopraProduction;
use App\Actions\Production\PostDehuskingProduction;
use App\Actions\Production\PostHuskChippingProduction;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Sales\PostDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryLot;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Inventory\StockQueryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductionRecipeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Finance Phase 1 is the accounting foundation and nothing more.
 *
 * Operational documents must NOT generate journal entries yet. The account mappings that
 * would decide which account each movement touches do not exist, so a journal posted now
 * would be a guess written permanently into the books.
 *
 * These tests exist so that integration, when it comes, is a deliberate act rather than
 * something that quietly started working.
 */
class NoOperationalAccountingTest extends TestCase
{
    use RefreshDatabase;

    private UnitOfMeasure $nos;

    private UnitOfMeasure $kg;

    /** @var array<string, Product> */
    private array $products = [];

    private Warehouse $main;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        foreach ([
            ['COCONUT', $this->nos, null],
            ['DEHUSKED-COCONUT', $this->nos, null],
            ['RAW-HUSK', $this->nos, null],
            ['COPRA', $this->kg, null],
            ['HUSK-7C', $this->kg, null],
            ['HUSK-9C', $this->kg, null],
            ['HUSK-10C', $this->kg, null],
            ['RAW-SHELL', $this->nos, $this->kg],
        ] as [$code, $primary, $secondary]) {
            $this->products[$code] = Product::factory()->create([
                'code' => $code,
                'primary_uom_id' => $primary->id,
                'secondary_uom_id' => $secondary?->id,
            ]);
        }

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);

        $this->seed(ProductionRecipeSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function product(string $code): Product
    {
        return $this->products[$code];
    }

    private function lotFor(string $code): InventoryLot
    {
        return app(CreateInventoryLot::class)->handle($this->product($code));
    }

    #[Test]
    public function posting_a_goods_receipt_creates_no_journal_entry(): void
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => Supplier::factory()->create()->id,
            'warehouse_id' => $this->main->id,
            'transport_cost' => '20000.000000',
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->product('COCONUT')->id,
            'quantity' => '10000.000',
            'unit_price' => '100.000000',
        ]);

        app(PostGoodsReceipt::class)->handle($receipt->refresh());

        // Stock moved; the books did not.
        $this->assertSame('10000.000', app(StockQueryService::class)->quantityForProduct($this->product('COCONUT')->id));
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalEntryLine::count());
    }

    #[Test]
    public function posting_a_delivery_creates_no_journal_entry(): void
    {
        $lot = $this->lotFor('COCONUT');

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->product('COCONUT')->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: '1000.000',
                lotId: $lot->id,
                unitCost: '100.000000',
            ),
        ]);

        $delivery = Delivery::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            'warehouse_id' => $this->main->id,
        ]);
        DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id,
            'product_id' => $this->product('COCONUT')->id,
            'lot_id' => $lot->id,
            'quantity' => '100.000',
            'unit_price' => '130.000000',
        ]);

        app(PostDelivery::class)->handle($delivery->refresh());

        $this->assertSame(0, JournalEntry::count());
    }

    #[Test]
    public function posting_any_production_run_creates_no_journal_entry(): void
    {
        // Dehusking.
        $coconutLot = $this->lotFor('COCONUT');
        $this->receive('COCONUT', '5000.000', '100.000000', $coconutLot);

        $dehusking = ProductionOrder::factory()->create([
            'production_type' => ProductionType::Dehusking,
            'warehouse_id' => $this->main->id,
        ]);
        ProductionInput::factory()->create([
            'production_order_id' => $dehusking->id,
            'product_id' => $this->product('COCONUT')->id,
            'lot_id' => $coconutLot->id,
            'quantity' => '3000.000',
        ]);
        $dehusking = app(PostDehuskingProduction::class)->handle($dehusking->refresh());

        $this->assertSame(0, JournalEntry::count(), 'Dehusking must not post a journal.');

        // Husk chipping, from the husk dehusking just produced.
        $huskLot = InventoryLot::findOrFail(
            $dehusking->outputs->firstWhere('product_id', $this->product('RAW-HUSK')->id)->lot_id
        );

        $chipping = ProductionOrder::factory()->huskChipping()->create(['warehouse_id' => $this->main->id]);
        ProductionInput::factory()->create([
            'production_order_id' => $chipping->id,
            'product_id' => $this->product('RAW-HUSK')->id,
            'lot_id' => $huskLot->id,
            'quantity' => '2000.000',
        ]);
        foreach ([['HUSK-7C', '150.500'], ['HUSK-9C', '205.250'], ['HUSK-10C', '281.750']] as [$code, $quantity]) {
            ProductionOutput::factory()->create([
                'production_order_id' => $chipping->id,
                'product_id' => $this->product($code)->id,
                'quantity' => $quantity,
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }
        app(PostHuskChippingProduction::class)->handle($chipping->refresh());

        $this->assertSame(0, JournalEntry::count(), 'Husk chipping must not post a journal.');

        // Copra, from the dehusked coconut.
        $dehuskedLot = InventoryLot::findOrFail(
            $dehusking->outputs->firstWhere('product_id', $this->product('DEHUSKED-COCONUT')->id)->lot_id
        );

        $copra = ProductionOrder::factory()->copra()->create(['warehouse_id' => $this->main->id]);
        ProductionInput::factory()->create([
            'production_order_id' => $copra->id,
            'product_id' => $this->product('DEHUSKED-COCONUT')->id,
            'lot_id' => $dehuskedLot->id,
            'quantity' => '1000.000',
        ]);
        ProductionOutput::factory()->create([
            'production_order_id' => $copra->id,
            'product_id' => $this->product('COPRA')->id,
            'quantity' => '190.500',
            'allocated_cost' => null,
            'unit_cost' => null,
        ]);
        ProductionOutput::factory()->create([
            'production_order_id' => $copra->id,
            'product_id' => $this->product('RAW-SHELL')->id,
            'quantity' => '1000.000',
            'secondary_quantity' => '420.750',
            'allocated_cost' => null,
            'unit_cost' => null,
        ]);
        app(PostCopraProduction::class)->handle($copra->refresh());

        $this->assertSame(0, JournalEntry::count(), 'Copra production must not post a journal.');
        $this->assertSame(0, JournalEntryLine::count());
    }

    #[Test]
    public function the_chart_of_accounts_exists_but_the_books_start_empty(): void
    {
        $this->assertSame(49, Account::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalEntryLine::count());
        $this->assertSame(0, FiscalPeriod::count());

        $report = app(AccountingBalanceService::class)->trialBalance();

        $this->assertSame([], $report['rows']);
        $this->assertSame('0.000000', $report['debit_total']);
        $this->assertSame('0.000000', $report['credit_total']);
        $this->assertTrue($report['balanced']);
    }

    /**
     * The precision guarantee Finance will depend on when it does integrate.
     */
    #[Test]
    public function the_authoritative_inventory_total_cost_is_untouched_by_finance(): void
    {
        $lot = $this->lotFor('RAW-HUSK');
        $this->receive('RAW-HUSK', '2000.000', '20.000000', $lot);

        $chipping = ProductionOrder::factory()->huskChipping()->create(['warehouse_id' => $this->main->id]);
        ProductionInput::factory()->create([
            'production_order_id' => $chipping->id,
            'product_id' => $this->product('RAW-HUSK')->id,
            'lot_id' => $lot->id,
            'quantity' => '2000.000',
        ]);
        foreach ([['HUSK-7C', '150.000'], ['HUSK-9C', '200.000'], ['HUSK-10C', '280.000']] as [$code, $quantity]) {
            ProductionOutput::factory()->create([
                'production_order_id' => $chipping->id,
                'product_id' => $this->product($code)->id,
                'quantity' => $quantity,
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }

        $posted = app(PostHuskChippingProduction::class)->handle($chipping->refresh());

        // 16,000 over 280 KG: the rate repeats, the money does not. This is the figure
        // Finance must post verbatim when integration arrives.
        $line = $posted->outputTransaction->lines->firstWhere('product_id', $this->product('HUSK-10C')->id);

        $this->assertSame('16000.000000', $line->total_cost);
        $this->assertSame('57.142857', $line->unit_cost);
        $this->assertSame('40000.000000', $posted->consumptionTransaction->lines->first()->total_cost);
    }

    private function receive(string $code, string $quantity, string $unitCost, InventoryLot $lot): void
    {
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $this->product($code)->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lot->id,
                unitCost: $unitCost,
            ),
        ]);
    }
}
