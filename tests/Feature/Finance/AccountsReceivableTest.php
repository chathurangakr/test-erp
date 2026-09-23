<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\CloseFiscalPeriod;
use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Receivables\CancelSalesInvoice;
use App\Actions\Receivables\PostCustomerReceipt;
use App\Actions\Receivables\PostSalesInvoice;
use App\Actions\Receivables\ReverseCustomerReceipt;
use App\Actions\Receivables\ReverseSalesInvoice;
use App\Actions\Sales\PostDelivery;
use App\Actions\Sales\ReverseDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\CustomerReceiptStatus;
use App\Enums\DeliveryStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\PricingBasis;
use App\Enums\SalesInvoiceStatus;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\FiscalPeriod;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\SalesBillingReconciliationService;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Database\Seeders\ProductionRecipeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Accounts receivable.
 *
 * A delivery moves stock and records what it cost — `Dr cost of sales / Cr inventory` — and
 * recognises no revenue. The sales invoice does that, later and possibly in parts, and the
 * receipt settles it. The invariant everything else serves:
 *
 *   Trade Receivables general-ledger balance  ==  total invoice outstanding
 *
 * The amount billed always comes from the delivery line's stored `sales_value`, which is what
 * keeps the dual-unit Raw Shell case honest without a second NOS-to-KG conversion.
 */
class AccountsReceivableTest extends TestCase
{
    use RefreshDatabase;

    private const CUTOVER = '2026-09-01';

    /** @var array<string, Product> */
    private array $products = [];

    private Customer $customer;

    private Customer $otherCustomer;

    private Warehouse $main;

    private FiscalPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        foreach ([
            ['COCONUT', $nos], ['DEHUSKED-COCONUT', $nos], ['RAW-HUSK', $nos], ['COPRA', $kg],
            ['HUSK-7C', $kg], ['HUSK-9C', $kg], ['HUSK-10C', $kg],
        ] as [$code, $uom]) {
            $this->products[$code] = Product::factory()->create(['code' => $code, 'primary_uom_id' => $uom->id]);
        }
        $this->products['RAW-SHELL'] = Product::factory()->create([
            'code' => 'RAW-SHELL', 'primary_uom_id' => $nos->id, 'secondary_uom_id' => $kg->id,
        ]);

        $this->customer = Customer::factory()->create(['code' => 'CUS-001', 'credit_days' => 30]);
        $this->otherCustomer = Customer::factory()->create(['code' => 'CUS-002']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);

        $this->seed(ProductionRecipeSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        // Money may only move through a configured cash or bank account.
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);

        $this->period = FiscalPeriod::factory()->create([
            'name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);

        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => self::CUTOVER]);
    }

    // ------------------------------------------------------------- helpers

    private function balances(): AccountingBalanceService
    {
        return app(AccountingBalanceService::class);
    }

    private function receivables(): AccountsReceivableService
    {
        return app(AccountsReceivableService::class);
    }

    private function billing(): SalesBillingReconciliationService
    {
        return app(SalesBillingReconciliationService::class);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function product(string $code): Product
    {
        return $this->products[$code];
    }

    /** Put stock on hand at a known cost. */
    private function stockUp(string $code, string $quantity, string $unitCost): InventoryLot
    {
        $lot = app(CreateInventoryLot::class)->handle($this->product($code));

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

        return $lot;
    }

    /**
     * Post a delivery.
     *
     * @param  list<array{code: string, quantity: string, price: string, lot: InventoryLot, secondary?: string, basis?: PricingBasis}>  $lines
     */
    private function deliver(array $lines, array $attributes = [], ?Customer $customer = null): Delivery
    {
        $delivery = Delivery::factory()->create(array_merge([
            'customer_id' => ($customer ?? $this->customer)->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => '2026-09-05',
        ], $attributes));

        foreach ($lines as $line) {
            DeliveryLine::factory()->create([
                'delivery_id' => $delivery->id,
                'product_id' => $this->product($line['code'])->id,
                'lot_id' => $line['lot']->id,
                'quantity' => $line['quantity'],
                'secondary_quantity' => $line['secondary'] ?? null,
                'pricing_basis' => $line['basis'] ?? PricingBasis::Primary,
                'unit_price' => $line['price'],
            ]);
        }

        return app(PostDelivery::class)->handle($delivery->refresh());
    }

    /**
     * A draft sales invoice billing the given delivered lines.
     *
     * @param  list<array{line: DeliveryLine, amount: string}>  $lines
     */
    private function draftInvoice(array $lines, ?Customer $customer = null, array $attributes = []): SalesInvoice
    {
        $invoice = SalesInvoice::factory()->create(array_merge([
            'customer_id' => ($customer ?? $this->customer)->id,
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-09-25',
        ], $attributes));

        foreach ($lines as $line) {
            SalesInvoiceLine::factory()->forDeliveryLine($line['line'], $line['amount'])->create([
                'sales_invoice_id' => $invoice->id,
            ]);
        }

        return $invoice->refresh();
    }

    private function postInvoice(SalesInvoice $invoice, ?int $userId = null): SalesInvoice
    {
        return app(PostSalesInvoice::class)->handle($invoice, $userId);
    }

    /**
     * @param  list<array{invoice: SalesInvoice, amount: string}>  $allocations
     */
    private function draftReceipt(array $allocations, string $amount, ?Customer $customer = null, array $attributes = []): CustomerReceipt
    {
        $receipt = CustomerReceipt::factory()->create(array_merge([
            'customer_id' => ($customer ?? $this->customer)->id,
            'receipt_date' => '2026-09-20',
            'receipt_account_id' => $this->account('1120')->id,
            'amount' => $amount,
        ], $attributes));

        foreach ($allocations as $allocation) {
            CustomerReceiptAllocation::factory()->create([
                'customer_receipt_id' => $receipt->id,
                'sales_invoice_id' => $allocation['invoice']->id,
                'amount' => $allocation['amount'],
            ]);
        }

        return $receipt->refresh();
    }

    private function postReceipt(CustomerReceipt $receipt, ?int $userId = null): CustomerReceipt
    {
        return app(PostCustomerReceipt::class)->handle($receipt, $userId);
    }

    private function amountOn(SalesInvoice|CustomerReceipt $document, string $accountCode, string $column): string
    {
        $accountId = $this->account($accountCode)->id;

        return DecimalMath::cost($document->journalEntry->lines->where('account_id', $accountId)->reduce(
            fn (string $carry, $line): string => DecimalMath::add($carry, DecimalMath::of($line->{$column})),
            DecimalMath::zero(),
        ));
    }

    /** The invariant this whole module exists to preserve. */
    private function assertLedgerAgrees(): void
    {
        $this->assertSame(
            $this->receivables()->totalOutstanding(),
            $this->balances()->balance($this->account('1210')),
            'Trade Receivables does not equal the outstanding invoice total',
        );

        $this->assertTrue($this->balances()->trialBalance()['balanced']);
    }

    /** A delivery of 2,000 coconut at 130, costing 102.50 each. */
    private function coconutDelivery(?Customer $customer = null): Delivery
    {
        $lot = $this->stockUp('COCONUT', '10000.000', '102.500000');

        return $this->deliver([
            ['code' => 'COCONUT', 'quantity' => '2000.000', 'price' => '130.000000', 'lot' => $lot],
        ], customer: $customer);
    }

    // ------------------------------------------------------------- configuration

    #[Test]
    public function the_trade_receivables_account_is_configured_and_validated(): void
    {
        $settings = AccountingSetting::current();

        $this->assertSame('1210', $settings->tradeReceivablesAccount->code);
        $this->assertTrue($settings->tradeReceivablesAccount->is_control_account);
        $this->assertFalse($settings->tradeReceivablesAccount->acceptsManualPosting());
    }

    #[Test]
    public function the_trade_receivables_account_must_be_an_asset(): void
    {
        $this->expectException(ValidationException::class);

        app(UpdateAccountingSettings::class)->handle([
            'trade_receivables_account_id' => $this->account('4110')->id,
        ]);
    }

    // ------------------------------------------------------------- the invoice document

    #[Test]
    public function a_draft_invoice_creates_no_journal_and_no_receivable(): void
    {
        $delivery = $this->coconutDelivery();
        $journalsBefore = JournalEntry::count();

        $invoice = $this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '260000.000000']]);

        $this->assertSame(SalesInvoiceStatus::Draft, $invoice->status);
        $this->assertSame($journalsBefore, JournalEntry::count());
        $this->assertSame('0.000000', $this->receivables()->totalOutstanding());

        // The delivered value is untouched — a draft has billed nothing.
        $this->assertSame('260000.000000', $this->billing()->remaining($delivery->lines->first()));
    }

    #[Test]
    public function an_inactive_customer_cannot_be_invoiced(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '1000.000000']]);

        $this->customer->update(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->postInvoice($invoice);
    }

    #[Test]
    public function an_invoice_needs_an_open_period_containing_its_date(): void
    {
        $delivery = $this->coconutDelivery();

        $outside = $this->draftInvoice(
            [['line' => $delivery->lines->first(), 'amount' => '1000.000000']],
            attributes: ['invoice_date' => '2026-10-05', 'due_date' => '2026-10-20'],
        );

        try {
            $this->postInvoice($outside);
            $this->fail('Expected an invoice outside any open period to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice_date', $e->errors());
        }

        $this->assertSame(SalesInvoiceStatus::Draft, $outside->fresh()->status);

        app(CloseFiscalPeriod::class)->handle($this->period);

        $this->expectException(ValidationException::class);
        $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '1000.000000']]));
    }

    #[Test]
    public function a_due_date_before_the_invoice_date_is_refused(): void
    {
        $delivery = $this->coconutDelivery();

        $invoice = $this->draftInvoice(
            [['line' => $delivery->lines->first(), 'amount' => '1000.000000']],
            attributes: ['invoice_date' => '2026-09-10', 'due_date' => '2026-09-05'],
        );

        $this->expectException(ValidationException::class);

        $this->postInvoice($invoice);
    }

    // ------------------------------------------------------------- revenue recognition

    #[Test]
    public function an_invoice_debits_receivables_and_credits_the_products_revenue_account(): void
    {
        $user = User::factory()->create();
        $delivery = $this->coconutDelivery();

        $invoice = $this->postInvoice(
            $this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '260000.000000']]),
            $user->id,
        );

        $this->assertSame(SalesInvoiceStatus::Posted, $invoice->status);
        $this->assertSame($user->id, $invoice->posted_by);
        $this->assertNotNull($invoice->posted_at);

        $this->assertSame('260000.000000', $this->amountOn($invoice, '1210', 'debit'));
        $this->assertSame('260000.000000', $this->amountOn($invoice, '4110', 'credit'));

        // The delivery's own COGS entry stands, and the invoice added no second one.
        $this->assertSame('205000.000000', $this->balances()->balance($this->account('5110')));
        $this->assertSame('0.000000', $this->amountOn($invoice, '5110', 'debit'));

        $this->assertLedgerAgrees();
    }

    #[Test]
    public function the_invoice_bills_the_deliverys_exact_stored_sales_value(): void
    {
        $delivery = $this->coconutDelivery();
        $line = $delivery->lines->first();

        // 2,000 x 130, computed at delivery and never recalculated here.
        $this->assertSame('260000.000000', $line->sales_value);
        $this->assertSame('260000.000000', $this->billing()->originalValue($line));

        $invoice = $this->postInvoice($this->draftInvoice([['line' => $line, 'amount' => '260000.000000']]));

        $this->assertSame('260000.000000', $invoice->total());
        $this->assertSame('0.000000', $this->billing()->remaining($line->refresh()));
    }

    /**
     * Raw shell leaves stock by the count but is priced by the measured weight. The invoice
     * bills the stored value and introduces no second conversion.
     */
    #[Test]
    public function raw_shell_priced_by_weight_bills_its_exact_delivered_value(): void
    {
        $lot = $this->stockUp('RAW-SHELL', '1000.000', '21.781250');

        $delivery = $this->deliver([[
            'code' => 'RAW-SHELL',
            'quantity' => '500.000',
            'secondary' => '208.750',
            'basis' => PricingBasis::Secondary,
            'price' => '80.000000',
            'lot' => $lot,
        ]]);

        $line = $delivery->lines->first();

        // 208.750 KG x 80 — the weight actually measured, not a pro-rata share of the count.
        $this->assertSame('16700.000000', $line->sales_value);

        $invoice = $this->postInvoice($this->draftInvoice([['line' => $line, 'amount' => '16700.000000']]));

        $this->assertSame('16700.000000', $this->amountOn($invoice, '1210', 'debit'));
        $this->assertSame('16700.000000', $this->amountOn($invoice, '4150', 'credit'));

        // The count is what left stock and what cost of sales followed: 500 NOS at the
        // weighted average, entirely independent of the 208.750 KG that was priced.
        $this->assertSame('500.000', app(StockQueryService::class)->quantityForProduct($this->product('RAW-SHELL')->id));
        $this->assertSame('10890.625000', $this->balances()->balance($this->account('5150')));
        $this->assertSame('10890.625000', $delivery->lines->first()->cost_of_goods_sold);

        $this->assertLedgerAgrees();
    }

    #[Test]
    public function a_multi_product_invoice_credits_each_products_revenue_account(): void
    {
        $coconutLot = $this->stockUp('COCONUT', '10000.000', '102.500000');
        $copraLot = $this->stockUp('COPRA', '1000.000', '343.011811');
        $shellLot = $this->stockUp('RAW-SHELL', '1000.000', '21.781250');

        $delivery = $this->deliver([
            ['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '100.000000', 'lot' => $coconutLot],
            ['code' => 'COPRA', 'quantity' => '100.000', 'price' => '500.000000', 'lot' => $copraLot],
            ['code' => 'RAW-SHELL', 'quantity' => '500.000', 'price' => '40.000000', 'lot' => $shellLot],
        ]);

        $invoice = $this->postInvoice($this->draftInvoice([
            ['line' => $delivery->lines[0], 'amount' => '100000.000000'],
            ['line' => $delivery->lines[1], 'amount' => '50000.000000'],
            ['line' => $delivery->lines[2], 'amount' => '20000.000000'],
        ]));

        $this->assertSame('170000.000000', $this->amountOn($invoice, '1210', 'debit'));
        $this->assertSame('100000.000000', $this->amountOn($invoice, '4110', 'credit'));
        $this->assertSame('50000.000000', $this->amountOn($invoice, '4140', 'credit'));
        $this->assertSame('20000.000000', $this->amountOn($invoice, '4150', 'credit'));

        // Debits equal credits, and the revenue credited equals the invoice's own lines.
        $journal = $invoice->journalEntry;
        $this->assertSame($journal->debitTotal(), $journal->creditTotal());
        $this->assertSame($invoice->total(), $this->amountOn($invoice, '1210', 'debit'));

        $this->assertLedgerAgrees();
    }

    // ------------------------------------------------------------- no inventory effect

    #[Test]
    public function neither_an_invoice_nor_a_receipt_touches_inventory(): void
    {
        $delivery = $this->coconutDelivery();
        $stock = app(StockQueryService::class);
        $coconutId = $this->product('COCONUT')->id;

        $before = [
            'quantity' => $stock->quantityForProduct($coconutId),
            'value' => $stock->valueForProduct($coconutId),
            'wac' => $stock->weightedAverageCostForProduct($coconutId),
            'transactions' => InventoryTransaction::count(),
            'cogs' => $this->balances()->balance($this->account('5110')),
        ];

        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '260000.000000']]));
        $this->postReceipt($this->draftReceipt([['invoice' => $invoice, 'amount' => '100000.000000']], '100000.000000'));

        $this->assertSame($before['quantity'], $stock->quantityForProduct($coconutId));
        $this->assertSame($before['value'], $stock->valueForProduct($coconutId));
        $this->assertSame($before['wac'], $stock->weightedAverageCostForProduct($coconutId));
        $this->assertSame($before['transactions'], InventoryTransaction::count());
        // And no second cost of sales entry.
        $this->assertSame($before['cogs'], $this->balances()->balance($this->account('5110')));
    }

    // ------------------------------------------------------------- partial billing

    #[Test]
    public function a_delivered_line_may_be_billed_by_several_invoices(): void
    {
        $delivery = $this->coconutDelivery();
        $line = $delivery->lines->first();

        $this->postInvoice($this->draftInvoice([['line' => $line, 'amount' => '200000.000000']]));
        $this->assertSame('60000.000000', $this->billing()->remaining($line->refresh()));

        $this->postInvoice($this->draftInvoice([['line' => $line, 'amount' => '60000.000000']]));

        $this->assertSame('0.000000', $this->billing()->remaining($line->refresh()));
        $this->assertSame('260000.000000', $this->receivables()->totalOutstanding());
        $this->assertSame('260000.000000', $this->balances()->balance($this->account('4110')));

        $this->assertLedgerAgrees();
    }

    #[Test]
    public function one_invoice_may_span_several_deliveries(): void
    {
        $lot = $this->stockUp('COCONUT', '10000.000', '102.500000');

        $first = $this->deliver([['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '130.000000', 'lot' => $lot]]);
        $second = $this->deliver([['code' => 'COCONUT', 'quantity' => '500.000', 'price' => '140.000000', 'lot' => $lot]]);

        $invoice = $this->postInvoice($this->draftInvoice([
            ['line' => $first->lines->first(), 'amount' => '130000.000000'],
            ['line' => $second->lines->first(), 'amount' => '70000.000000'],
        ]));

        $this->assertSame('200000.000000', $invoice->total());
        $this->assertSame('0.000000', $this->billing()->deliveryRemaining($first->fresh(['lines'])));
        $this->assertSame('0.000000', $this->billing()->deliveryRemaining($second->fresh(['lines'])));

        $this->assertLedgerAgrees();
    }

    #[Test]
    public function an_invoice_cannot_bill_more_than_the_delivered_value(): void
    {
        $delivery = $this->coconutDelivery();
        $line = $delivery->lines->first();

        $overbill = $this->draftInvoice([['line' => $line, 'amount' => '260000.000001']]);

        try {
            $this->postInvoice($overbill);
            $this->fail('Expected the over-billing to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('still unbilled', implode(' ', $e->errors()['lines.0.amount']));
        }

        $this->assertSame(SalesInvoiceStatus::Draft, $overbill->fresh()->status);
        $this->assertSame('260000.000000', $this->billing()->remaining($line->refresh()));
    }

    #[Test]
    public function two_lines_on_one_invoice_against_one_delivered_line_are_checked_together(): void
    {
        $delivery = $this->coconutDelivery();
        $line = $delivery->lines->first();

        // Each alone is within the value; together they exceed it.
        $invoice = $this->draftInvoice([
            ['line' => $line, 'amount' => '200000.000000'],
            ['line' => $line, 'amount' => '60000.000001'],
        ]);

        $this->expectException(ValidationException::class);

        $this->postInvoice($invoice);
    }

    // ------------------------------------------------------------- eligibility

    #[Test]
    public function a_pre_cutover_delivery_cannot_be_invoiced(): void
    {
        $lot = $this->stockUp('COCONUT', '10000.000', '102.500000');

        // Dated before the cutover, so it posted no cost of sales to the ledger.
        $delivery = $this->deliver(
            [['code' => 'COCONUT', 'quantity' => '100.000', 'price' => '130.000000', 'lot' => $lot]],
            ['delivery_date' => '2026-08-20'],
        );

        $this->assertFalse($this->billing()->isBillable($delivery));

        try {
            $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '100.000000']]));
            $this->fail('Expected a pre-cutover delivery to be unbillable.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no cost of sales in the ledger', implode(' ', $e->errors()['lines.0.delivery_line_id']));
        }
    }

    #[Test]
    public function a_reversed_delivery_cannot_be_invoiced(): void
    {
        $delivery = $this->coconutDelivery();
        app(ReverseDelivery::class)->handle($delivery);

        $this->assertFalse($this->billing()->isBillable($delivery->fresh(['lines'])));

        $this->expectException(ValidationException::class);

        $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '100.000000']]));
    }

    #[Test]
    public function an_invoice_cannot_bill_another_customers_delivery(): void
    {
        $delivery = $this->coconutDelivery($this->otherCustomer);

        $invoice = $this->draftInvoice(
            [['line' => $delivery->lines->first(), 'amount' => '1000.000000']],
            $this->customer,
        );

        try {
            $this->postInvoice($invoice);
            $this->fail('Expected a cross-customer billing to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('different customer', implode(' ', $e->errors()['lines.0.delivery_line_id']));
        }
    }

    // ------------------------------------------------------------- immutability

    #[Test]
    public function a_posted_invoice_is_immutable_and_cannot_be_posted_twice(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '1000.000000']]));

        try {
            $invoice->update(['description' => 'tampered']);
            $this->fail('Expected the update to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('immutable', $e->getMessage());
        }

        try {
            $invoice->delete();
            $this->fail('Expected the delete to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Only a draft', $e->getMessage());
        }

        $this->expectException(ValidationException::class);
        $this->postInvoice($invoice->fresh());
    }

    #[Test]
    public function a_draft_invoice_can_be_cancelled_and_affects_nothing(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '1000.000000']]);

        $cancelled = app(CancelSalesInvoice::class)->handle($invoice);

        $this->assertSame(SalesInvoiceStatus::Cancelled, $cancelled->status);
        $this->assertSame('260000.000000', $this->billing()->remaining($delivery->lines->first()));
        $this->assertSame('0.000000', $this->receivables()->totalOutstanding());
        $this->assertNotNull(SalesInvoice::find($invoice->id));
    }

    // ------------------------------------------------------------- invoice reversal

    #[Test]
    public function reversing_an_invoice_reverses_its_journal_and_releases_the_billing(): void
    {
        $delivery = $this->coconutDelivery();
        $line = $delivery->lines->first();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $line, 'amount' => '260000.000000']]));

        $reversed = app(ReverseSalesInvoice::class)->handle($invoice);

        $this->assertSame(SalesInvoiceStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversal_journal_entry_id);

        // The delivered value is unbilled again, revenue is back to nothing, and the
        // delivery's own cost of sales is untouched.
        $this->assertSame('260000.000000', $this->billing()->remaining($line->refresh()));
        $this->assertSame('0.000000', $this->balances()->balance($this->account('4110')));
        $this->assertSame('0.000000', $this->balances()->balance($this->account('1210')));
        $this->assertSame('205000.000000', $this->balances()->balance($this->account('5110')));

        $this->assertCount(1, $reversed->lines()->get());
        $this->assertLedgerAgrees();
    }

    #[Test]
    public function an_invoice_cannot_be_reversed_twice(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '1000.000000']]));
        app(ReverseSalesInvoice::class)->handle($invoice);

        $this->expectException(ValidationException::class);

        app(ReverseSalesInvoice::class)->handle($invoice->fresh());
    }

    #[Test]
    public function an_invoice_with_a_live_receipt_cannot_be_reversed(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '100000.000000']]));
        $receipt = $this->postReceipt($this->draftReceipt([['invoice' => $invoice, 'amount' => '40000.000000']], '40000.000000'));

        try {
            app(ReverseSalesInvoice::class)->handle($invoice->fresh());
            $this->fail('Expected the reversal to be blocked while a receipt stands.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Reverse the associated customer receipt', implode(' ', $e->errors()['status']));
        }

        app(ReverseCustomerReceipt::class)->handle($receipt);

        $reversed = app(ReverseSalesInvoice::class)->handle($invoice->fresh());
        $this->assertSame(SalesInvoiceStatus::Reversed, $reversed->status);
    }

    // ------------------------------------------------------------- delivery reversal guard

    #[Test]
    public function a_delivery_that_has_been_invoiced_cannot_be_reversed(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '260000.000000']]));

        try {
            app(ReverseDelivery::class)->handle($delivery->fresh());
            $this->fail('Expected the delivery reversal to be blocked.');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['status']);
            $this->assertStringContainsString('Reverse the associated sales invoice', $message);
            $this->assertStringContainsString($invoice->invoice_number, $message);
        }

        // Nothing moved: stock, cost of sales and revenue all stand.
        $this->assertSame(DeliveryStatus::Posted, $delivery->fresh()->status);
        $this->assertSame('8000.000', app(StockQueryService::class)->quantityForProduct($this->product('COCONUT')->id));
        $this->assertLedgerAgrees();
    }

    #[Test]
    public function a_delivery_becomes_reversible_once_its_invoices_are_reversed(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '260000.000000']]));

        app(ReverseSalesInvoice::class)->handle($invoice);
        app(ReverseDelivery::class)->handle($delivery->fresh());

        $this->assertSame(DeliveryStatus::Reversed, $delivery->fresh()->status);
        $this->assertSame('10000.000', app(StockQueryService::class)->quantityForProduct($this->product('COCONUT')->id));
        $this->assertSame('0.000000', $this->balances()->balance($this->account('5110')));
        $this->assertSame('0.000000', $this->balances()->balance($this->account('4110')));
        $this->assertLedgerAgrees();
    }

    #[Test]
    public function an_uninvoiced_delivery_still_reverses_normally(): void
    {
        $delivery = $this->coconutDelivery();

        app(ReverseDelivery::class)->handle($delivery);

        $this->assertSame(DeliveryStatus::Reversed, $delivery->fresh()->status);
        $this->assertSame('0.000000', $this->balances()->balance($this->account('5110')));
    }

    // ------------------------------------------------------------- receipts

    #[Test]
    public function a_receipt_debits_the_bank_and_credits_receivables(): void
    {
        $user = User::factory()->create();
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '260000.000000']]));

        $receipt = $this->postReceipt(
            $this->draftReceipt([['invoice' => $invoice, 'amount' => '100000.000000']], '100000.000000'),
            $user->id,
        );

        $this->assertSame(CustomerReceiptStatus::Posted, $receipt->status);
        $this->assertSame($user->id, $receipt->posted_by);

        $this->assertSame('100000.000000', $this->amountOn($receipt, '1120', 'debit'));
        $this->assertSame('100000.000000', $this->amountOn($receipt, '1210', 'credit'));

        // No revenue is created by a receipt.
        $this->assertSame('0.000000', $this->amountOn($receipt, '4110', 'credit'));
        $this->assertSame('260000.000000', $this->balances()->balance($this->account('4110')));

        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        $this->assertSame('160000.000000', $this->receivables()->outstanding($invoice->fresh()));

        $this->assertLedgerAgrees();
    }

    #[Test]
    public function receiving_the_balance_marks_the_invoice_paid(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '100000.000000']]));

        $this->postReceipt($this->draftReceipt([['invoice' => $invoice, 'amount' => '40000.000000']], '40000.000000'));
        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        $this->postReceipt($this->draftReceipt([['invoice' => $invoice->fresh(), 'amount' => '60000.000000']], '60000.000000'));

        $this->assertSame(SalesInvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('0.000000', $this->receivables()->outstanding($invoice->fresh()));
        $this->assertSame('0.000000', $this->balances()->balance($this->account('1210')));

        $this->assertLedgerAgrees();
    }

    #[Test]
    public function one_receipt_may_settle_several_invoices(): void
    {
        $delivery = $this->coconutDelivery();
        $line = $delivery->lines->first();

        $first = $this->postInvoice($this->draftInvoice([['line' => $line, 'amount' => '160000.000000']]));
        $second = $this->postInvoice($this->draftInvoice([['line' => $line, 'amount' => '100000.000000']]));

        $this->postReceipt($this->draftReceipt([
            ['invoice' => $first, 'amount' => '160000.000000'],
            ['invoice' => $second, 'amount' => '40000.000000'],
        ], '200000.000000'));

        $this->assertSame(SalesInvoiceStatus::Paid, $first->fresh()->status);
        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $second->fresh()->status);
        $this->assertSame('60000.000000', $this->receivables()->totalOutstanding());

        $this->assertLedgerAgrees();
    }

    #[Test]
    public function a_receipt_must_be_fully_allocated(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '100000.000000']]));

        $under = $this->draftReceipt([['invoice' => $invoice, 'amount' => '40000.000000']], '50000.000000');

        try {
            $this->postReceipt($under);
            $this->fail('Expected an unallocated remainder to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Every rupee must be allocated', implode(' ', $e->errors()['allocations']));
        }

        $this->assertSame(CustomerReceiptStatus::Draft, $under->fresh()->status);
    }

    #[Test]
    public function a_receipt_cannot_exceed_what_an_invoice_is_owed(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '100000.000000']]));

        $this->expectException(ValidationException::class);

        $this->postReceipt($this->draftReceipt([['invoice' => $invoice, 'amount' => '100000.000001']], '100000.000001'));
    }

    #[Test]
    public function a_receipt_cannot_settle_another_customers_invoice(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '100000.000000']]));

        $receipt = $this->draftReceipt(
            [['invoice' => $invoice, 'amount' => '1000.000000']],
            '1000.000000',
            $this->otherCustomer,
        );

        $this->expectException(ValidationException::class);

        $this->postReceipt($receipt);
    }

    #[Test]
    public function a_receipt_account_must_be_an_ordinary_asset_account(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '1000.000000']]));

        // 1400 Other Current Assets is the important one: an active, hand-postable ASSET
        // that is simply not configured as somewhere money is held.
        foreach (['1210', '1310', '4110', '1400'] as $code) {
            $receipt = $this->draftReceipt(
                [['invoice' => $invoice, 'amount' => '1000.000000']],
                '1000.000000',
                attributes: ['receipt_account_id' => $this->account($code)->id],
            );

            try {
                $this->postReceipt($receipt);
                $this->fail("Expected account {$code} to be refused as a receipt account.");
            } catch (ValidationException) {
                // expected
            }
        }

        $cash = $this->draftReceipt(
            [['invoice' => $invoice, 'amount' => '1000.000000']],
            '1000.000000',
            attributes: ['receipt_account_id' => $this->account('1110')->id],
        );

        $this->assertSame(CustomerReceiptStatus::Posted, $this->postReceipt($cash)->status);
    }

    #[Test]
    public function a_receipt_needs_an_open_period_and_an_active_customer(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '1000.000000']]));

        $outside = $this->draftReceipt(
            [['invoice' => $invoice, 'amount' => '1000.000000']],
            '1000.000000',
            attributes: ['receipt_date' => '2026-10-10'],
        );

        try {
            $this->postReceipt($outside);
            $this->fail('Expected a receipt outside any open period to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('receipt_date', $e->errors());
        }

        $this->customer->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->postReceipt($this->draftReceipt([['invoice' => $invoice, 'amount' => '1000.000000']], '1000.000000'));
    }

    // ------------------------------------------------------------- receipt reversal

    #[Test]
    public function reversing_a_receipt_restores_the_outstanding_balance_and_the_status(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '100000.000000']]));
        $receipt = $this->postReceipt($this->draftReceipt([['invoice' => $invoice, 'amount' => '100000.000000']], '100000.000000'));

        $this->assertSame(SalesInvoiceStatus::Paid, $invoice->fresh()->status);

        $reversed = app(ReverseCustomerReceipt::class)->handle($receipt);

        $this->assertSame(CustomerReceiptStatus::Reversed, $reversed->status);
        $this->assertSame(SalesInvoiceStatus::Posted, $invoice->fresh()->status);
        $this->assertSame('100000.000000', $this->receivables()->outstanding($invoice->fresh()));
        $this->assertSame('100000.000000', $this->balances()->balance($this->account('1210')));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('1120')->id));

        // The allocations survive — nothing is deleted.
        $this->assertCount(1, $reversed->allocations()->get());

        $this->assertLedgerAgrees();
    }

    #[Test]
    public function a_receipt_cannot_be_reversed_twice(): void
    {
        $delivery = $this->coconutDelivery();
        $invoice = $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '1000.000000']]));
        $receipt = $this->postReceipt($this->draftReceipt([['invoice' => $invoice, 'amount' => '1000.000000']], '1000.000000'));

        app(ReverseCustomerReceipt::class)->handle($receipt);

        $this->expectException(ValidationException::class);

        app(ReverseCustomerReceipt::class)->handle($receipt->fresh());
    }

    // ------------------------------------------------------------- reporting

    #[Test]
    public function the_unbilled_delivery_report_shows_value_billed_and_remaining(): void
    {
        $delivery = $this->coconutDelivery();

        $unbilled = $this->billing()->report();
        $this->assertCount(1, $unbilled['rows']);
        $this->assertSame('260000.000000', $unbilled['rows'][0]['sales_value']);
        $this->assertSame('0.000000', $unbilled['rows'][0]['billed_amount']);
        $this->assertSame('UNBILLED', $unbilled['rows'][0]['status']);

        $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first(), 'amount' => '200000.000000']]));

        $partial = $this->billing()->report();
        $this->assertSame('200000.000000', $partial['rows'][0]['billed_amount']);
        $this->assertSame('60000.000000', $partial['rows'][0]['remaining_amount']);
        $this->assertSame('PARTIALLY_BILLED', $partial['rows'][0]['status']);

        $this->postInvoice($this->draftInvoice([['line' => $delivery->lines->first()->refresh(), 'amount' => '60000.000000']]));

        $full = $this->billing()->report();
        $this->assertSame('FULLY_BILLED', $full['rows'][0]['status']);
        $this->assertSame('0.000000', $full['remaining_total']);
        $this->assertSame('260000.000000', $full['billed_total']);
    }

    #[Test]
    public function the_aging_report_buckets_by_how_overdue_an_invoice_is(): void
    {
        $lot = $this->stockUp('COCONUT', '100000.000', '102.500000');
        $delivery = $this->deliver([['code' => 'COCONUT', 'quantity' => '10000.000', 'price' => '130.000000', 'lot' => $lot]]);
        $line = $delivery->lines->first();

        // All invoiced inside the open September period; aged at the end of December, by
        // which point the due dates are 0, 15, 45, 75 and 120 days past.
        foreach ([
            ['2027-01-31', '10000.000000'],
            ['2026-12-16', '20000.000000'],
            ['2026-11-16', '30000.000000'],
            ['2026-10-17', '40000.000000'],
            ['2026-09-02', '50000.000000'],
        ] as [$due, $amount]) {
            $this->postInvoice($this->draftInvoice(
                [['line' => $line->refresh(), 'amount' => $amount]],
                attributes: ['invoice_date' => '2026-09-01', 'due_date' => $due],
            ));
        }

        $aging = $this->receivables()->aging(Carbon::parse('2026-12-31'));

        $this->assertCount(1, $aging['rows']);
        $row = $aging['rows'][0];

        $this->assertSame('10000.000000', $row['current']);
        $this->assertSame('20000.000000', $row['days_1_30']);
        $this->assertSame('30000.000000', $row['days_31_60']);
        $this->assertSame('40000.000000', $row['days_61_90']);
        $this->assertSame('50000.000000', $row['days_90_plus']);
        $this->assertSame('150000.000000', $row['total']);
        $this->assertSame('150000.000000', $aging['totals']['total']);
    }

    #[Test]
    public function a_reversed_invoice_and_a_reversed_receipt_leave_the_aging_correct(): void
    {
        $delivery = $this->coconutDelivery();
        $line = $delivery->lines->first();

        $kept = $this->postInvoice($this->draftInvoice([['line' => $line, 'amount' => '100000.000000']]));
        $dropped = $this->postInvoice($this->draftInvoice([['line' => $line->refresh(), 'amount' => '150000.000000']]));

        $receipt = $this->postReceipt($this->draftReceipt([['invoice' => $kept, 'amount' => '40000.000000']], '40000.000000'));

        $this->assertSame('210000.000000', $this->receivables()->aging()['totals']['total']);

        app(ReverseSalesInvoice::class)->handle($dropped);
        $this->assertSame('60000.000000', $this->receivables()->aging()['totals']['total']);

        app(ReverseCustomerReceipt::class)->handle($receipt);
        $this->assertSame('100000.000000', $this->receivables()->aging()['totals']['total']);

        $this->assertLedgerAgrees();
    }

    #[Test]
    public function the_customer_outstanding_total_is_derived_per_customer(): void
    {
        $lot = $this->stockUp('COCONUT', '10000.000', '102.500000');
        $mine = $this->deliver([['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '130.000000', 'lot' => $lot]]);
        $theirs = $this->deliver(
            [['code' => 'COCONUT', 'quantity' => '500.000', 'price' => '130.000000', 'lot' => $lot]],
            customer: $this->otherCustomer,
        );

        $this->postInvoice($this->draftInvoice([['line' => $mine->lines->first(), 'amount' => '130000.000000']]));
        $this->postInvoice($this->draftInvoice(
            [['line' => $theirs->lines->first(), 'amount' => '65000.000000']],
            $this->otherCustomer,
        ));

        $this->assertSame('130000.000000', $this->receivables()->customerOutstanding($this->customer));
        $this->assertSame('65000.000000', $this->receivables()->customerOutstanding($this->otherCustomer));
        $this->assertSame('195000.000000', $this->receivables()->totalOutstanding());

        $this->assertLedgerAgrees();
    }

    // ------------------------------------------------------------- the critical invariants

    #[Test]
    public function the_general_ledger_reconciles_to_receivables_through_a_whole_cycle(): void
    {
        $delivery = $this->coconutDelivery();
        $line = $delivery->lines->first();

        $this->assertLedgerAgrees();

        $first = $this->postInvoice($this->draftInvoice([['line' => $line, 'amount' => '200000.000000']]));
        $this->assertLedgerAgrees();

        $second = $this->postInvoice($this->draftInvoice([['line' => $line->refresh(), 'amount' => '60000.000000']]));
        $this->assertLedgerAgrees();

        // All delivered value is now recognised as revenue.
        $this->assertSame('260000.000000', $this->balances()->balance($this->account('4110')));
        $this->assertSame('260000.000000', $this->balances()->balance($this->account('1210')));

        $this->postReceipt($this->draftReceipt([['invoice' => $first, 'amount' => '200000.000000']], '200000.000000'));
        $this->assertLedgerAgrees();

        $partial = $this->postReceipt($this->draftReceipt([['invoice' => $second, 'amount' => '20000.000000']], '20000.000000'));
        $this->assertLedgerAgrees();

        $this->assertSame('40000.000000', $this->balances()->balance($this->account('1210')));
        $this->assertSame('40000.000000', $this->receivables()->totalOutstanding());

        app(ReverseCustomerReceipt::class)->handle($partial);
        $this->assertLedgerAgrees();
        $this->assertSame('60000.000000', $this->balances()->balance($this->account('1210')));

        // Cost of sales and inventory were established by the delivery and never moved.
        $this->assertSame('205000.000000', $this->balances()->balance($this->account('5110')));
        $this->assertSame('820000.000000', app(StockQueryService::class)->valueForProduct($this->product('COCONUT')->id));
    }

    /**
     * Revenue accounts legitimately accept manual journals, so the reconciliation is per
     * system-generated invoice journal to its own lines — never a global revenue total.
     */
    #[Test]
    public function each_invoice_journal_credits_exactly_what_its_lines_bill(): void
    {
        $coconutLot = $this->stockUp('COCONUT', '10000.000', '102.500000');
        $copraLot = $this->stockUp('COPRA', '1000.000', '343.011811');

        $delivery = $this->deliver([
            ['code' => 'COCONUT', 'quantity' => '1000.000', 'price' => '130.000000', 'lot' => $coconutLot],
            ['code' => 'COPRA', 'quantity' => '100.000', 'price' => '500.000000', 'lot' => $copraLot],
        ]);

        $invoice = $this->postInvoice($this->draftInvoice([
            ['line' => $delivery->lines[0], 'amount' => '130000.000000'],
            ['line' => $delivery->lines[1], 'amount' => '50000.000000'],
        ]));

        $journal = $invoice->journalEntry;
        $receivablesId = $this->account('1210')->id;

        $revenueCredited = DecimalMath::cost($journal->lines->where('account_id', '!=', $receivablesId)->reduce(
            fn (string $carry, $line): string => DecimalMath::add($carry, DecimalMath::of($line->credit)),
            DecimalMath::zero(),
        ));

        $this->assertSame($invoice->total(), $revenueCredited);
        $this->assertSame('180000.000000', $revenueCredited);

        // A manual journal elsewhere in revenue does not disturb this per-invoice check.
        $manual = JournalEntry::factory()->create([
            'fiscal_period_id' => $this->period->id,
            'journal_date' => '2026-09-15',
            'source_type' => null,
        ]);
        JournalEntryLine::factory()->debit('500.000000')->create([
            'journal_entry_id' => $manual->id,
            'account_id' => $this->account('1110')->id,
        ]);
        JournalEntryLine::factory()->credit('500.000000')->create([
            'journal_entry_id' => $manual->id,
            'account_id' => $this->account('4110')->id,
        ]);
        app(PostJournalEntry::class)->handle($manual->refresh());

        $this->assertSame($invoice->total(), $revenueCredited);
        $this->assertLedgerAgrees();
    }

    #[Test]
    public function manual_posting_to_the_receivables_control_account_is_still_blocked(): void
    {
        $manual = JournalEntry::factory()->create([
            'fiscal_period_id' => $this->period->id,
            'journal_date' => '2026-09-15',
            'source_type' => null,
        ]);
        JournalEntryLine::factory()->debit('100.000000')->create([
            'journal_entry_id' => $manual->id,
            'account_id' => $this->account('1210')->id,
        ]);
        JournalEntryLine::factory()->credit('100.000000')->create([
            'journal_entry_id' => $manual->id,
            'account_id' => $this->account('4110')->id,
        ]);

        try {
            app(PostJournalEntry::class)->handle($manual->refresh());
            $this->fail('Expected manual posting to 1210 to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.account_id', $e->errors());
        }

        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('1210')->id));
    }
}
