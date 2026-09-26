<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\CloseFiscalPeriod;
use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\TransferStock;
use App\Actions\Production\PostCopraProduction;
use App\Actions\Production\PostDehuskingProduction;
use App\Actions\Production\PostHuskChippingProduction;
use App\Actions\Production\ReverseProduction;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\Actions\Sales\PostDelivery;
use App\Actions\Sales\ReverseDelivery;
use App\Enums\GoodsReceiptStatus;
use App\Enums\JournalEntryStatus;
use App\Enums\ProductionType;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\ProductAccountMapping;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Database\Seeders\ProductionRecipeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Inventory operations reaching the general ledger.
 *
 * The rule under test throughout is that the books record the inventory ledger's
 * **authoritative `total_cost`** — never a value rebuilt from a rounded unit rate — so the
 * two ledgers agree to the last decimal, and every generated journal balances exactly.
 *
 * The other half is the cutover: until an accountant sets a start date, nothing changes.
 */
class InventoryAccountingTest extends TestCase
{
    use RefreshDatabase;

    private const CUTOVER = '2026-09-01';

    private UnitOfMeasure $nos;

    private UnitOfMeasure $kg;

    /** @var array<string, Product> */
    private array $products = [];

    private Supplier $supplier;

    private Customer $customer;

    private Warehouse $main;

    private Warehouse $store;

    private FiscalPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $this->kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        foreach ([
            ['COCONUT', 'Whole Coconut', $this->nos, null],
            ['DEHUSKED-COCONUT', 'Dehusked Coconut', $this->nos, null],
            ['RAW-HUSK', 'Raw Coconut Husk', $this->nos, null],
            ['COPRA', 'Copra', $this->kg, null],
            ['HUSK-7C', '7C Husk Chips', $this->kg, null],
            ['HUSK-9C', '9C Husk Chips', $this->kg, null],
            ['HUSK-10C', '10C Husk Chips', $this->kg, null],
            ['RAW-SHELL', 'Raw Coconut Shell', $this->nos, $this->kg],
        ] as [$code, $name, $primary, $secondary]) {
            $this->products[$code] = Product::factory()->create([
                'code' => $code,
                'name' => $name,
                'primary_uom_id' => $primary->id,
                'secondary_uom_id' => $secondary?->id,
            ]);
        }

        $this->supplier = Supplier::factory()->create(['code' => 'TEST-SUPPLIER']);
        $this->customer = Customer::factory()->create(['code' => 'TEST-CUSTOMER']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->store = Warehouse::factory()->create(['code' => 'STORE']);

        $this->seed(ProductionRecipeSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);

        $this->period = FiscalPeriod::factory()->create([
            'name' => 'September 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
        ]);
    }

    // ------------------------------------------------------------- helpers

    private function enableAccounting(string $from = self::CUTOVER): void
    {
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => $from]);
    }

    private function balances(): AccountingBalanceService
    {
        return app(AccountingBalanceService::class);
    }

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    private function product(string $code): Product
    {
        return $this->products[$code];
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function lotFor(string $code): InventoryLot
    {
        return app(CreateInventoryLot::class)->handle($this->product($code));
    }

    /**
     * @param  list<array{code: string, quantity: string, price: string}>  $lines
     */
    private function receive(array $lines, array $attributes = []): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create(array_merge([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'receipt_date' => '2026-09-10',
        ], $attributes));

        foreach ($lines as $line) {
            GoodsReceiptLine::factory()->create([
                'goods_receipt_id' => $receipt->id,
                'product_id' => $this->product($line['code'])->id,
                'quantity' => $line['quantity'],
                'unit_price' => $line['price'],
            ]);
        }

        return app(PostGoodsReceipt::class)->handle($receipt->refresh());
    }

    /**
     * @param  list<array{code: string, quantity: string, price: string, lot: int}>  $lines
     */
    private function deliver(array $lines, array $attributes = []): Delivery
    {
        $delivery = Delivery::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => '2026-09-12',
        ], $attributes));

        foreach ($lines as $line) {
            DeliveryLine::factory()->create([
                'delivery_id' => $delivery->id,
                'product_id' => $this->product($line['code'])->id,
                'lot_id' => $line['lot'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['price'],
            ]);
        }

        return app(PostDelivery::class)->handle($delivery->refresh());
    }

    /**
     * @param  list<array{code: string, quantity: string, secondary?: string}>  $outputs
     */
    private function produce(
        ProductionType $type,
        string $inputCode,
        string $quantity,
        int $lotId,
        array $outputs = [],
        string $date = '2026-09-15',
    ): ProductionOrder {
        $order = ProductionOrder::factory()->create([
            'production_type' => $type,
            'warehouse_id' => $this->main->id,
            'production_date' => $date,
        ]);

        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->product($inputCode)->id,
            'lot_id' => $lotId,
            'quantity' => $quantity,
        ]);

        foreach ($outputs as $output) {
            ProductionOutput::factory()->create([
                'production_order_id' => $order->id,
                'product_id' => $this->product($output['code'])->id,
                'quantity' => $output['quantity'],
                'secondary_quantity' => $output['secondary'] ?? null,
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }

        $action = match ($type) {
            ProductionType::Dehusking => PostDehuskingProduction::class,
            ProductionType::HuskChipping => PostHuskChippingProduction::class,
            ProductionType::Copra => PostCopraProduction::class,
        };

        return app($action)->handle($order->refresh());
    }

    private function journalFor(object $source): ?JournalEntry
    {
        return app(RecordOperationalJournal::class)->originalJournalFor($source);
    }

    /** Sum of one side of a journal. */
    private function sideTotal(JournalEntry $entry, string $column): string
    {
        return DecimalMath::cost($entry->lines->reduce(
            fn (string $carry, $line): string => DecimalMath::add($carry, DecimalMath::of($line->{$column})),
            DecimalMath::zero(),
        ));
    }

    /** What one journal debits or credits a given account. */
    private function amountOn(JournalEntry $entry, string $accountCode, string $column): string
    {
        $accountId = $this->account($accountCode)->id;

        return DecimalMath::cost($entry->lines->where('account_id', $accountId)->reduce(
            fn (string $carry, $line): string => DecimalMath::add($carry, DecimalMath::of($line->{$column})),
            DecimalMath::zero(),
        ));
    }

    // ------------------------------------------------------------- configuration

    #[Test]
    public function the_official_products_are_mapped_and_the_qa_product_is_not(): void
    {
        $this->assertSame(8, ProductAccountMapping::count());

        $coconut = $this->product('COCONUT')->accountMapping;
        $this->assertSame('1310', $coconut->inventoryAccount->code);
        $this->assertSame('5110', $coconut->cogsAccount->code);
        $this->assertSame('4110', $coconut->revenueAccount->code);

        // The three chip grades share one set of accounts.
        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            $this->assertSame('1340', $this->product($code)->accountMapping->inventoryAccount->code);
            $this->assertSame('5130', $this->product($code)->accountMapping->cogsAccount->code);
        }

        // A hand-created product gets no mapping — inventing one would put guessed figures
        // permanently into the books.
        $qa = Product::factory()->create(['code' => 'QA-COCO-260913', 'primary_uom_id' => $this->nos->id]);
        $this->seed(ProductAccountMappingSeeder::class);

        $this->assertNull($qa->fresh()->accountMapping);
        $this->assertSame(8, ProductAccountMapping::count());
    }

    #[Test]
    public function the_seeder_configures_grni_without_inventing_a_cutover_date(): void
    {
        $settings = AccountingSetting::current();

        $this->assertNotNull($settings);
        $this->assertSame('2120', $settings->grniAccount->code);
        $this->assertNull($settings->operational_accounting_start_date);
        $this->assertFalse($settings->isOperationalAccountingEnabled());
    }

    #[Test]
    public function a_mapping_must_point_at_the_right_class_of_account(): void
    {
        $user = User::factory()->admin()->create();
        $coconut = $this->product('COCONUT');

        // Inventory must be an ASSET.
        $this->actingAs($user)->patch(route('accounting-setup.mapping', $coconut), [
            'inventory_account_id' => $this->account('4110')->id,
            'cogs_account_id' => $this->account('5110')->id,
        ])->assertSessionHasErrors('inventory_account_id');

        // COGS must be a COST OF SALES account.
        $this->actingAs($user)->patch(route('accounting-setup.mapping', $coconut), [
            'inventory_account_id' => $this->account('1310')->id,
            'cogs_account_id' => $this->account('6400')->id,
        ])->assertSessionHasErrors('cogs_account_id');

        // Revenue must be a REVENUE account.
        $this->actingAs($user)->patch(route('accounting-setup.mapping', $coconut), [
            'inventory_account_id' => $this->account('1310')->id,
            'cogs_account_id' => $this->account('5110')->id,
            'revenue_account_id' => $this->account('1110')->id,
        ])->assertSessionHasErrors('revenue_account_id');
    }

    #[Test]
    public function the_grni_account_must_be_a_liability(): void
    {
        $this->expectException(ValidationException::class);

        app(UpdateAccountingSettings::class)->handle(['grni_account_id' => $this->account('1310')->id]);
    }

    // ------------------------------------------------------------- the cutover

    #[Test]
    public function no_journal_is_created_while_the_cutover_is_not_set(): void
    {
        $this->receive([['code' => 'COCONUT', 'quantity' => '10000.000', 'price' => '100.000000']]);

        $this->assertSame('10000.000', $this->stock()->quantityForProduct($this->product('COCONUT')->id));
        $this->assertSame(0, JournalEntry::count());
    }

    #[Test]
    public function a_document_dated_before_the_cutover_creates_no_journal(): void
    {
        $this->enableAccounting('2026-09-10');

        $receipt = $this->receive(
            [['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000']],
            ['receipt_date' => '2026-09-09'],
        );

        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->status);
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->product('COCONUT')->id));
        $this->assertNull($this->journalFor($receipt));
        $this->assertSame(0, JournalEntry::count());
    }

    #[Test]
    public function a_document_dated_on_the_cutover_creates_a_journal(): void
    {
        $this->enableAccounting('2026-09-10');

        $receipt = $this->receive(
            [['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000']],
            ['receipt_date' => '2026-09-10'],
        );

        $this->assertNotNull($this->journalFor($receipt));
    }

    #[Test]
    public function the_cutover_date_is_frozen_once_operational_journals_exist(): void
    {
        $this->enableAccounting();
        $this->receive([['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000']]);

        try {
            $this->enableAccounting('2026-09-20');
            $this->fail('Expected the cutover date to be frozen.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('operational_accounting_start_date', $e->errors());
        }

        $this->assertSame(self::CUTOVER, AccountingSetting::current()->operational_accounting_start_date->toDateString());

        // Clearing it is refused for the same reason.
        $this->expectException(ValidationException::class);
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => null]);
    }

    #[Test]
    public function a_manual_journal_does_not_freeze_the_cutover(): void
    {
        $this->enableAccounting();

        // A manual entry is independent of the cutover, so it must not lock it.
        JournalEntry::factory()->create(['fiscal_period_id' => $this->period->id, 'source_type' => null]);

        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-05']);

        $this->assertSame('2026-09-05', AccountingSetting::current()->operational_accounting_start_date->toDateString());
    }

    // ------------------------------------------------------------- goods receipt

    #[Test]
    public function a_goods_receipt_debits_inventory_and_credits_grni_at_the_authoritative_value(): void
    {
        $this->enableAccounting();

        // 10,000 at 100 plus 20,000 transport and 5,000 loading = 1,025,000 landed.
        $receipt = $this->receive(
            [['code' => 'COCONUT', 'quantity' => '10000.000', 'price' => '100.000000']],
            ['transport_cost' => '20000.000000', 'loading_cost' => '5000.000000'],
        );

        $journal = $this->journalFor($receipt);
        $this->assertNotNull($journal);
        $this->assertSame(JournalEntryStatus::Posted, $journal->status);

        $this->assertSame('1025000.000000', $this->amountOn($journal, '1310', 'debit'));
        $this->assertSame('1025000.000000', $this->amountOn($journal, '2120', 'credit'));
        $this->assertSame($this->sideTotal($journal, 'debit'), $this->sideTotal($journal, 'credit'));

        // Exactly the PURCHASE_RECEIPT ledger value — landed cost included, because it is
        // already part of total_cost. Finance does not re-allocate transport.
        $ledger = $receipt->inventoryTransaction->lines->first()->total_cost;
        $this->assertSame($ledger, $this->amountOn($journal, '1310', 'debit'));
        $this->assertSame('1025000.000000', $ledger);
    }

    #[Test]
    public function a_multi_product_receipt_balances_and_splits_by_account(): void
    {
        $this->enableAccounting();

        // A landed cost that does not divide evenly: 100 over three equal lines.
        $receipt = $this->receive([
            ['code' => 'COCONUT', 'quantity' => '10.000', 'price' => '10.000000'],
            ['code' => 'RAW-HUSK', 'quantity' => '10.000', 'price' => '10.000000'],
            ['code' => 'COPRA', 'quantity' => '10.000', 'price' => '10.000000'],
        ], ['transport_cost' => '100.000000']);

        $journal = $this->journalFor($receipt);

        $this->assertSame('133.333333', $this->amountOn($journal, '1310', 'debit'));
        $this->assertSame('133.333333', $this->amountOn($journal, '1330', 'debit'));
        $this->assertSame('133.333334', $this->amountOn($journal, '1350', 'debit'));
        $this->assertSame('400.000000', $this->amountOn($journal, '2120', 'credit'));

        $this->assertSame('400.000000', $this->sideTotal($journal, 'debit'));
        $this->assertSame('400.000000', $this->sideTotal($journal, 'credit'));
    }

    #[Test]
    public function an_unmapped_product_cannot_be_received_once_accounting_applies(): void
    {
        $this->enableAccounting();

        $qa = Product::factory()->create(['code' => 'QA-COCO-260913', 'primary_uom_id' => $this->nos->id]);

        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'receipt_date' => '2026-09-10',
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $qa->id,
            'quantity' => '100.000',
            'unit_price' => '10.000000',
        ]);

        try {
            app(PostGoodsReceipt::class)->handle($receipt->refresh());
            $this->fail('Expected an unmapped product to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no accounting mapping', implode(' ', $e->errors()['product_id']));
        }

        // Everything rolled back: no stock, no lot, no journal, still a draft.
        $this->assertSame('0.000', $this->stock()->quantityForProduct($qa->id));
        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame(0, InventoryLot::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(GoodsReceiptStatus::Draft, $receipt->fresh()->status);
    }

    #[Test]
    public function a_document_with_no_open_fiscal_period_cannot_be_posted_at_all(): void
    {
        $this->enableAccounting();

        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            // October: no period covers it.
            'receipt_date' => '2026-10-05',
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->product('COCONUT')->id,
            'quantity' => '1000.000',
            'unit_price' => '100.000000',
        ]);

        try {
            app(PostGoodsReceipt::class)->handle($receipt->refresh());
            $this->fail('Expected the posting to be refused with no open period.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('journal_date', $e->errors());
        }

        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->product('COCONUT')->id));
        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame(0, InventoryLot::count());
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(GoodsReceiptStatus::Draft, $receipt->fresh()->status);
    }

    #[Test]
    public function a_closed_fiscal_period_also_blocks_the_operational_posting(): void
    {
        $this->enableAccounting();
        app(CloseFiscalPeriod::class)->handle($this->period);

        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'receipt_date' => '2026-09-10',
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->product('COCONUT')->id,
            'quantity' => '1000.000',
            'unit_price' => '100.000000',
        ]);

        $this->expectException(ValidationException::class);

        app(PostGoodsReceipt::class)->handle($receipt->refresh());
    }

    // ------------------------------------------------------------- delivery

    #[Test]
    public function a_delivery_debits_cost_of_sales_and_credits_inventory_with_no_revenue(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive(
            [['code' => 'COCONUT', 'quantity' => '10000.000', 'price' => '100.000000']],
            ['transport_cost' => '20000.000000', 'loading_cost' => '5000.000000'],
        );
        $lot = (int) $receipt->lines->first()->lot_id;

        // 2,000 at the 102.50 weighted average = 205,000 — the selling price is irrelevant.
        $delivery = $this->deliver([
            ['code' => 'COCONUT', 'quantity' => '2000.000', 'price' => '130.000000', 'lot' => $lot],
        ]);

        $journal = $this->journalFor($delivery);
        $this->assertNotNull($journal);

        $this->assertSame('205000.000000', $this->amountOn($journal, '5110', 'debit'));
        $this->assertSame('205000.000000', $this->amountOn($journal, '1310', 'credit'));
        $this->assertSame($this->sideTotal($journal, 'debit'), $this->sideTotal($journal, 'credit'));

        // Exactly the SALES_ISSUE ledger value.
        $this->assertSame(
            $delivery->inventoryTransaction->lines->first()->total_cost,
            $this->amountOn($journal, '5110', 'debit'),
        );

        // NO revenue and NO receivable: a delivery is a physical issue. Revenue belongs to
        // the sales invoice, which is a later phase.
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('4110')->id));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('1210')->id));
        $this->assertSame('0.000000', $this->amountOn($journal, '4110', 'credit'));
    }

    #[Test]
    public function the_selling_price_never_reaches_the_books(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive([['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000']]);
        $lot = (int) $receipt->lines->first()->lot_id;

        // Two identical issues at wildly different prices must produce identical journals.
        $cheap = $this->deliver([['code' => 'COCONUT', 'quantity' => '100.000', 'price' => '1.000000', 'lot' => $lot]]);
        $dear = $this->deliver([['code' => 'COCONUT', 'quantity' => '100.000', 'price' => '9999.000000', 'lot' => $lot]]);

        $this->assertSame(
            $this->sideTotal($this->journalFor($cheap), 'debit'),
            $this->sideTotal($this->journalFor($dear), 'debit'),
        );
        $this->assertSame('10000.000000', $this->sideTotal($this->journalFor($dear), 'debit'));
    }

    // ------------------------------------------------------------- production

    #[Test]
    public function dehusking_moves_value_between_inventory_accounts_exactly(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive(
            [['code' => 'COCONUT', 'quantity' => '10000.000', 'price' => '100.000000']],
            ['transport_cost' => '20000.000000', 'loading_cost' => '5000.000000'],
        );
        $lot = (int) $receipt->lines->first()->lot_id;

        // 3,000 at 102.50 = 307,500, split 85 / 15.
        $order = $this->produce(ProductionType::Dehusking, 'COCONUT', '3000.000', $lot);

        $journal = $this->journalFor($order);
        $this->assertNotNull($journal);

        $this->assertSame('261375.000000', $this->amountOn($journal, '1320', 'debit'));
        $this->assertSame('46125.000000', $this->amountOn($journal, '1330', 'debit'));
        $this->assertSame('307500.000000', $this->amountOn($journal, '1310', 'credit'));

        $this->assertSame('307500.000000', $this->sideTotal($journal, 'debit'));
        $this->assertSame('307500.000000', $this->sideTotal($journal, 'credit'));
    }

    /**
     * The repeating-rate case: 16,000 over 280 KG is Rs.57.142857 per KG, which cannot be
     * multiplied back. The books must carry the total, not the rate.
     */
    #[Test]
    public function husk_chipping_uses_the_authoritative_total_not_the_repeating_rate(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive([['code' => 'RAW-HUSK', 'quantity' => '2000.000', 'price' => '20.000000']]);
        $lot = (int) $receipt->lines->first()->lot_id;

        $order = $this->produce(ProductionType::HuskChipping, 'RAW-HUSK', '2000.000', $lot, [
            ['code' => 'HUSK-7C', 'quantity' => '150.000'],
            ['code' => 'HUSK-9C', 'quantity' => '200.000'],
            ['code' => 'HUSK-10C', 'quantity' => '280.000'],
        ]);

        $journal = $this->journalFor($order);

        // All three grades map to 1340, so the account carries the whole 40,000 — across
        // three separate, individually described lines.
        $this->assertSame('40000.000000', $this->amountOn($journal, '1340', 'debit'));
        $this->assertSame('40000.000000', $this->amountOn($journal, '1330', 'credit'));
        $this->assertSame(3, $journal->lines->where('account_id', $this->account('1340')->id)->count());

        $this->assertSame($this->sideTotal($journal, 'debit'), $this->sideTotal($journal, 'credit'));

        // Not 279.999999… of anything: the exact allocated figure reached the books.
        $tenC = $journal->lines->firstWhere('description', 'Produced HUSK-10C');
        $this->assertSame('16000.000000', $tenC->debit);
    }

    #[Test]
    public function copra_production_credits_dehusked_coconut_and_debits_both_outputs(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive([['code' => 'DEHUSKED-COCONUT', 'quantity' => '2000.000', 'price' => '100.000000']]);
        $lot = (int) $receipt->lines->first()->lot_id;

        // 1,000 at 100 = 100,000, split 75 / 25.
        $order = $this->produce(ProductionType::Copra, 'DEHUSKED-COCONUT', '1000.000', $lot, [
            ['code' => 'COPRA', 'quantity' => '200.000'],
            ['code' => 'RAW-SHELL', 'quantity' => '1000.000', 'secondary' => '420.000'],
        ]);

        $journal = $this->journalFor($order);

        $this->assertSame('75000.000000', $this->amountOn($journal, '1350', 'debit'));
        $this->assertSame('25000.000000', $this->amountOn($journal, '1360', 'debit'));
        $this->assertSame('100000.000000', $this->amountOn($journal, '1320', 'credit'));

        $this->assertSame('100000.000000', $this->sideTotal($journal, 'debit'));
        $this->assertSame('100000.000000', $this->sideTotal($journal, 'credit'));
    }

    /**
     * Raw shell is counted in NOS and weighed in KG. Only the count carries value, so the
     * measured weight must have no effect on the journal whatsoever.
     */
    #[Test]
    public function the_measured_shell_weight_does_not_affect_the_accounting_amount(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive([['code' => 'DEHUSKED-COCONUT', 'quantity' => '4000.000', 'price' => '100.000000']]);
        $lot = (int) $receipt->lines->first()->lot_id;

        $light = $this->produce(ProductionType::Copra, 'DEHUSKED-COCONUT', '1000.000', $lot, [
            ['code' => 'COPRA', 'quantity' => '200.000'],
            ['code' => 'RAW-SHELL', 'quantity' => '1000.000', 'secondary' => '300.000'],
        ]);

        $heavy = $this->produce(ProductionType::Copra, 'DEHUSKED-COCONUT', '1000.000', $lot, [
            ['code' => 'COPRA', 'quantity' => '200.000'],
            ['code' => 'RAW-SHELL', 'quantity' => '1000.000', 'secondary' => '900.000'],
        ]);

        // Three times the weight, identical accounting.
        $this->assertSame(
            $this->amountOn($this->journalFor($light), '1360', 'debit'),
            $this->amountOn($this->journalFor($heavy), '1360', 'debit'),
        );
        $this->assertSame('25000.000000', $this->amountOn($this->journalFor($heavy), '1360', 'debit'));
    }

    // ------------------------------------------------------------- transfer

    #[Test]
    public function a_warehouse_transfer_creates_no_journal(): void
    {
        $this->enableAccounting();

        $this->receive([['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000']]);
        $journalsBefore = JournalEntry::count();

        app(TransferStock::class)->handle($this->product('COCONUT'), $this->main, $this->store, '400.000');

        // The company owns the same product, valued the same, in the same account.
        $this->assertSame($journalsBefore, JournalEntry::count());
        $this->assertSame('100000.000000', $this->balances()->netBalance($this->account('1310')->id));
        $this->assertSame('1000.000', $this->stock()->quantityForProduct($this->product('COCONUT')->id));
    }

    // ------------------------------------------------------------- reversal

    #[Test]
    public function reversing_a_goods_receipt_reverses_its_journal_exactly(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive([['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000']]);
        $original = $this->journalFor($receipt);

        app(ReverseGoodsReceipt::class)->handle($receipt);

        $reversal = JournalEntry::where('reversal_of_id', $original->id)->firstOrFail();

        $this->assertSame(JournalEntryStatus::Reversed, $original->fresh()->status);
        $this->assertSame('100000.000000', $this->amountOn($reversal, '1310', 'credit'));
        $this->assertSame('100000.000000', $this->amountOn($reversal, '2120', 'debit'));

        // Both accounts back to nothing, and the trial balance still balances.
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('1310')->id));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('2120')->id));
        $this->assertTrue($this->balances()->trialBalance()['balanced']);
    }

    #[Test]
    public function reversing_a_delivery_reverses_its_journal_exactly(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive([['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000']]);
        $lot = (int) $receipt->lines->first()->lot_id;
        $delivery = $this->deliver([['code' => 'COCONUT', 'quantity' => '100.000', 'price' => '130.000000', 'lot' => $lot]]);

        $original = $this->journalFor($delivery);

        app(ReverseDelivery::class)->handle($delivery);

        $reversal = JournalEntry::where('reversal_of_id', $original->id)->firstOrFail();

        $this->assertSame('10000.000000', $this->amountOn($reversal, '5110', 'credit'));
        $this->assertSame('10000.000000', $this->amountOn($reversal, '1310', 'debit'));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('5110')->id));

        // The receipt's value is back in inventory, untouched.
        $this->assertSame('100000.000000', $this->balances()->netBalance($this->account('1310')->id));
    }

    #[Test]
    public function reversing_each_production_type_reverses_its_journal_exactly(): void
    {
        $this->enableAccounting();

        // Dehusking.
        $receipt = $this->receive([['code' => 'COCONUT', 'quantity' => '5000.000', 'price' => '100.000000']]);
        $lot = (int) $receipt->lines->first()->lot_id;
        $dehusking = $this->produce(ProductionType::Dehusking, 'COCONUT', '3000.000', $lot);

        app(ReverseProduction::class)->handle($dehusking);

        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('1320')->id));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('1330')->id));
        $this->assertSame('500000.000000', $this->balances()->netBalance($this->account('1310')->id));

        // Husk chipping.
        $huskReceipt = $this->receive([['code' => 'RAW-HUSK', 'quantity' => '2000.000', 'price' => '20.000000']]);
        $huskLot = (int) $huskReceipt->lines->first()->lot_id;
        $chipping = $this->produce(ProductionType::HuskChipping, 'RAW-HUSK', '2000.000', $huskLot, [
            ['code' => 'HUSK-7C', 'quantity' => '150.000'],
            ['code' => 'HUSK-9C', 'quantity' => '200.000'],
            ['code' => 'HUSK-10C', 'quantity' => '280.000'],
        ]);

        app(ReverseProduction::class)->handle($chipping);

        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('1340')->id));
        $this->assertSame('40000.000000', $this->balances()->netBalance($this->account('1330')->id));

        // Copra.
        $dehuskedReceipt = $this->receive([['code' => 'DEHUSKED-COCONUT', 'quantity' => '2000.000', 'price' => '100.000000']]);
        $dehuskedLot = (int) $dehuskedReceipt->lines->first()->lot_id;
        $copra = $this->produce(ProductionType::Copra, 'DEHUSKED-COCONUT', '1000.000', $dehuskedLot, [
            ['code' => 'COPRA', 'quantity' => '200.000'],
            ['code' => 'RAW-SHELL', 'quantity' => '1000.000', 'secondary' => '420.000'],
        ]);

        app(ReverseProduction::class)->handle($copra);

        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('1350')->id));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('1360')->id));
        $this->assertSame('200000.000000', $this->balances()->netBalance($this->account('1320')->id));

        $this->assertTrue($this->balances()->trialBalance()['balanced']);
    }

    #[Test]
    public function reversing_a_pre_cutover_document_reverses_no_journal(): void
    {
        $this->enableAccounting('2026-09-20');

        $receipt = $this->receive(
            [['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000']],
            ['receipt_date' => '2026-09-10'],
        );

        $this->assertNull($this->journalFor($receipt));

        app(ReverseGoodsReceipt::class)->handle($receipt);

        $this->assertSame(GoodsReceiptStatus::Reversed, $receipt->fresh()->status);
        $this->assertSame(0, JournalEntry::count());
    }

    // ------------------------------------------------------------- duplicates and source

    #[Test]
    public function a_source_document_gets_at_most_one_original_journal(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive([['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000']]);

        $this->assertSame(1, JournalEntry::where('source_id', $receipt->id)
            ->where('source_type', GoodsReceipt::class)
            ->whereNull('reversal_of_id')
            ->count());

        // A retry is refused, not quietly doubled.
        $this->expectException(ValidationException::class);

        app(RecordOperationalJournal::class)->forGoodsReceipt($receipt, $receipt->inventoryTransaction);
    }

    #[Test]
    public function a_reversal_journal_cannot_be_created_twice(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive([['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000']]);
        app(ReverseGoodsReceipt::class)->handle($receipt);

        $this->expectException(ValidationException::class);

        app(RecordOperationalJournal::class)->reverseFor($receipt->fresh());
    }

    #[Test]
    public function every_generated_journal_names_its_source_document(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive([['code' => 'COCONUT', 'quantity' => '5000.000', 'price' => '100.000000']]);
        $lot = (int) $receipt->lines->first()->lot_id;
        $delivery = $this->deliver([['code' => 'COCONUT', 'quantity' => '100.000', 'price' => '130.000000', 'lot' => $lot]]);
        $order = $this->produce(ProductionType::Dehusking, 'COCONUT', '1000.000', $lot);

        $this->assertSame(GoodsReceipt::class, $this->journalFor($receipt)->source_type);
        $this->assertSame($receipt->id, (int) $this->journalFor($receipt)->source_id);

        $this->assertSame(Delivery::class, $this->journalFor($delivery)->source_type);
        $this->assertSame(ProductionOrder::class, $this->journalFor($order)->source_type);
        $this->assertSame($order->id, (int) $this->journalFor($order)->source_id);
    }

    // ------------------------------------------------------------- control accounts

    #[Test]
    public function the_system_may_post_to_control_accounts_but_a_person_may_not(): void
    {
        $this->enableAccounting();

        // The system does it every time a receipt posts.
        $receipt = $this->receive([['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000']]);
        $this->assertSame('100000.000000', $this->amountOn($this->journalFor($receipt), '2120', 'credit'));

        // A person cannot.
        $manual = JournalEntry::factory()->create([
            'fiscal_period_id' => $this->period->id,
            'journal_date' => '2026-09-15',
            'source_type' => null,
        ]);
        JournalEntryLine::factory()->debit('100.000000')->create([
            'journal_entry_id' => $manual->id,
            'account_id' => $this->account('2120')->id,
        ]);
        JournalEntryLine::factory()->credit('100.000000')->create([
            'journal_entry_id' => $manual->id,
            'account_id' => $this->account('1110')->id,
        ]);

        $this->expectException(ValidationException::class);

        app(PostJournalEntry::class)->handle($manual->refresh());
    }

    // ------------------------------------------------------------- reconciliation

    #[Test]
    public function the_general_ledger_reconciles_to_the_inventory_ledger(): void
    {
        $this->enableAccounting();

        $receipt = $this->receive(
            [['code' => 'COCONUT', 'quantity' => '10000.000', 'price' => '100.000000']],
            ['transport_cost' => '20000.000000', 'loading_cost' => '5000.000000'],
        );
        $lot = (int) $receipt->lines->first()->lot_id;

        $this->deliver([['code' => 'COCONUT', 'quantity' => '2000.000', 'price' => '130.000000', 'lot' => $lot]]);

        $dehusking = $this->produce(ProductionType::Dehusking, 'COCONUT', '3000.000', $lot);
        $huskLot = (int) $dehusking->outputs->firstWhere('product_id', $this->product('RAW-HUSK')->id)->lot_id;
        $dehuskedLot = (int) $dehusking->outputs->firstWhere('product_id', $this->product('DEHUSKED-COCONUT')->id)->lot_id;

        $this->produce(ProductionType::HuskChipping, 'RAW-HUSK', '2000.000', $huskLot, [
            ['code' => 'HUSK-7C', 'quantity' => '150.500'],
            ['code' => 'HUSK-9C', 'quantity' => '205.250'],
            ['code' => 'HUSK-10C', 'quantity' => '281.750'],
        ]);

        $this->produce(ProductionType::Copra, 'DEHUSKED-COCONUT', '1000.000', $dehuskedLot, [
            ['code' => 'COPRA', 'quantity' => '190.500'],
            ['code' => 'RAW-SHELL', 'quantity' => '1000.000', 'secondary' => '420.750'],
        ]);

        // Every inventory control account equals the inventory ledger value of the products
        // mapped to it. This is the whole point of the integration.
        $expected = [
            '1310' => ['COCONUT'],
            '1320' => ['DEHUSKED-COCONUT'],
            '1330' => ['RAW-HUSK'],
            '1340' => ['HUSK-7C', 'HUSK-9C', 'HUSK-10C'],
            '1350' => ['COPRA'],
            '1360' => ['RAW-SHELL'],
        ];

        foreach ($expected as $code => $productCodes) {
            $ledgerValue = DecimalMath::zero();

            foreach ($productCodes as $productCode) {
                $ledgerValue = DecimalMath::add(
                    $ledgerValue,
                    DecimalMath::of($this->stock()->valueForProduct($this->product($productCode)->id)),
                );
            }

            $this->assertSame(
                DecimalMath::cost($ledgerValue),
                $this->balances()->netBalance($this->account($code)->id),
                "Account {$code} does not reconcile to the inventory ledger",
            );
        }

        $this->assertTrue($this->balances()->trialBalance()['balanced']);
    }

    #[Test]
    public function the_trial_balance_stays_balanced_after_every_step(): void
    {
        $this->enableAccounting();

        $assertBalanced = function (string $step): void {
            $report = $this->balances()->trialBalance();

            $this->assertTrue($report['balanced'], "Trial balance broke after {$step}");
            $this->assertSame($report['debit_total'], $report['credit_total'], $step);
        };

        $receipt = $this->receive(
            [['code' => 'COCONUT', 'quantity' => '10000.000', 'price' => '100.000000']],
            ['transport_cost' => '20000.000000', 'loading_cost' => '5000.000000'],
        );
        $assertBalanced('the goods receipt');

        $lot = (int) $receipt->lines->first()->lot_id;
        $this->deliver([['code' => 'COCONUT', 'quantity' => '2000.000', 'price' => '130.000000', 'lot' => $lot]]);
        $assertBalanced('the delivery');

        $dehusking = $this->produce(ProductionType::Dehusking, 'COCONUT', '3000.000', $lot);
        $assertBalanced('dehusking');

        $huskLot = (int) $dehusking->outputs->firstWhere('product_id', $this->product('RAW-HUSK')->id)->lot_id;
        $dehuskedLot = (int) $dehusking->outputs->firstWhere('product_id', $this->product('DEHUSKED-COCONUT')->id)->lot_id;

        $this->produce(ProductionType::HuskChipping, 'RAW-HUSK', '2000.000', $huskLot, [
            ['code' => 'HUSK-7C', 'quantity' => '150.500'],
            ['code' => 'HUSK-9C', 'quantity' => '205.250'],
            ['code' => 'HUSK-10C', 'quantity' => '281.750'],
        ]);
        $assertBalanced('husk chipping');

        $this->produce(ProductionType::Copra, 'DEHUSKED-COCONUT', '1000.000', $dehuskedLot, [
            ['code' => 'COPRA', 'quantity' => '190.500'],
            ['code' => 'RAW-SHELL', 'quantity' => '1000.000', 'secondary' => '420.750'],
        ]);
        $assertBalanced('copra production');

        // What the books say after the whole run, given no invoices exist yet:
        //   GRNI carries the purchase accrual, COGS carries what was sold,
        //   and inventory carries the rest. That is expected for this phase.
        $this->assertSame('1025000.000000', $this->balances()->balance($this->account('2120')));
        $this->assertSame('205000.000000', $this->balances()->balance($this->account('5110')));
    }
}
