<?php

namespace Tests\Feature\Integration;

use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Payables\PostSupplierInvoice;
use App\Actions\Payables\PostSupplierPayment;
use App\Actions\Payables\SaveSupplierInvoice;
use App\Actions\Payables\SaveSupplierPayment;
use App\Actions\Production\PostDehuskingProduction;
use App\Actions\Production\PostHuskChippingProduction;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Receivables\PostCustomerReceipt;
use App\Actions\Receivables\PostSalesInvoice;
use App\Actions\Receivables\SaveCustomerReceipt;
use App\Actions\Receivables\SaveSalesInvoice;
use App\Actions\Sales\PostDelivery;
use App\Enums\DeliveryStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductionType;
use App\Enums\SalesInvoiceStatus;
use App\Enums\SupplierInvoiceLineType;
use App\Enums\SupplierInvoiceStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Inventory\StockQueryService;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The husk side of the factory, end to end, with accounting switched on.
 *
 *   buy 100 coconut → dehusk them at Rs. 3 labour each → buy 500 raw husk
 *   → one pool of 600 husks → chip: 7C 40 KG, 7C 100 L, cocopeat 100 L (no husk taken)
 *   → deliver 20 KG of 7C using 180 husks → the pool holds 420
 *   → invoice it, take part of the money by cash, leave the rest on credit
 *   → pay the suppliers part by cheque
 *
 * Every figure is read from the ledgers and the books, never from a stored total. The
 * scenario and its arithmetic are in docs/END_TO_END_VALIDATION.md §10.
 */
class HuskProductionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $main;

    private Supplier $supplier;

    private Customer $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->supplier = Supplier::factory()->create(['code' => 'SUP-001']);
        $this->customer = Customer::factory()->create(['code' => 'CUS-001', 'credit_days' => 30]);
        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);

        // The real master data: units, products with their lot policy, recipes, chart and
        // mappings — exactly what a fresh installation seeds.
        $this->seedProductionRecipes();
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);

        FiscalPeriod::factory()->create(['name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);
    }

    // ================================================================= helpers

    private function product(string $code): Product
    {
        return Product::query()->where('code', $code)->firstOrFail();
    }

    private function account(string $code): Account
    {
        return Account::query()->where('code', $code)->firstOrFail();
    }

    private function balance(string $code): string
    {
        return app(AccountingBalanceService::class)->balance($this->account($code));
    }

    private function stock(): StockQueryService
    {
        return app(StockQueryService::class);
    }

    private function receive(string $code, string $quantity, string $price): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create([
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'receipt_date' => '2026-09-10',
        ]);

        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->product($code)->id,
            'quantity' => $quantity,
            'unit_price' => $price,
        ]);

        return app(PostGoodsReceipt::class)->handle($receipt->refresh());
    }

    /**
     * @param  list<array{code: string, quantity: string, price: string, husks?: string}>  $lines
     */
    private function draftDelivery(array $lines): Delivery
    {
        $delivery = Delivery::factory()->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'delivery_date' => '2026-09-14',
        ]);

        foreach ($lines as $line) {
            DeliveryLine::factory()->create([
                'delivery_id' => $delivery->id,
                'product_id' => $this->product($line['code'])->id,
                'lot_id' => null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['price'],
                'backflush_quantity' => $line['husks'] ?? null,
            ]);
        }

        return $delivery->refresh();
    }

    /**
     * Steps 1-5: coconut in, dehusked with labour, husk bought, chips and cocopeat made.
     *
     * @return array{coconut_grn: GoodsReceipt, husk_grn: GoodsReceipt, dehusking: ProductionOrder, chipping: ProductionOrder}
     */
    private function produce(): array
    {
        // 1. Buy 100 whole coconut at Rs. 50.
        $coconutGrn = $this->receive('COCONUT', '100.000', '50.000000');

        // 2. Dehusk all 100 at Rs. 3.00 labour per coconut.
        $order = ProductionOrder::factory()->create([
            'production_type' => ProductionType::Dehusking,
            'warehouse_id' => $this->main->id,
            'production_date' => '2026-09-11',
            'labour_rate' => '3.000000',
        ]);
        ProductionInput::factory()->create([
            'production_order_id' => $order->id,
            'product_id' => $this->product('COCONUT')->id,
            'lot_id' => null,
            'quantity' => '100.000',
        ]);
        $dehusking = app(PostDehuskingProduction::class)->handle($order);

        // 3. Buy 500 raw husk at Rs. 12.
        $huskGrn = $this->receive('RAW-HUSK', '500.000', '12.000000');

        // 4. Chip: no husk is taken. 7C weighed in KG AND measured in litres — two stock items.
        $chip = ProductionOrder::factory()->create([
            'production_type' => ProductionType::HuskChipping,
            'warehouse_id' => $this->main->id,
            'production_date' => '2026-09-12',
        ]);
        foreach (['HUSK-7C' => '40.000', 'HUSK-7C-L' => '100.000', 'COCOPEAT-L' => '100.000'] as $code => $quantity) {
            ProductionOutput::factory()->create([
                'production_order_id' => $chip->id,
                'product_id' => $this->product($code)->id,
                'quantity' => $quantity,
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }
        $chipping = app(PostHuskChippingProduction::class)->handle($chip);

        return ['coconut_grn' => $coconutGrn, 'husk_grn' => $huskGrn, 'dehusking' => $dehusking, 'chipping' => $chipping];
    }

    // ================================================================= tests

    #[Test]
    public function dehusking_labour_becomes_the_husk_cost_and_the_books_record_it(): void
    {
        $flow = $this->produce();
        $dehusking = $flow['dehusking'];

        // 100 x 50 to dehusked coconut; 100 x 3.00 labour to raw husk.
        $this->assertSame('5000.000000', $dehusking->inputValue());
        $this->assertSame('300.000000', $dehusking->labour_cost);
        $this->assertSame('5300.000000', $dehusking->outputValue());

        // Dr 1320 5,000 + Dr 1330 300 / Cr 1310 5,000 + Cr 6310 300 — no cash, no payable.
        $journal = JournalEntry::query()
            ->where('source_type', ProductionOrder::class)
            ->where('source_id', $dehusking->id)
            ->firstOrFail();
        $byAccount = $journal->lines->load('account')->mapWithKeys(fn ($line) => [
            $line->account->code => [$line->debit, $line->credit],
        ]);

        $this->assertSame(['5000.000000', '0.000000'], $byAccount['1320']);
        $this->assertSame(['300.000000', '0.000000'], $byAccount['1330']);
        $this->assertSame(['0.000000', '5000.000000'], $byAccount['1310']);
        $this->assertSame(['0.000000', '300.000000'], $byAccount['6310']);
        $this->assertCount(4, $byAccount);

        // The labour offsets wages until the husk is sold: a credit on the contra-expense.
        $this->assertSame('-300.000000', $this->balance('6310'));
    }

    #[Test]
    public function purchased_and_dehusked_husk_form_one_pool_and_chipping_takes_none_of_it(): void
    {
        $flow = $this->produce();
        $husk = $this->product('RAW-HUSK');

        // 500 bought + 100 from dehusking = 600 in one pool, at one average:
        // (6,000 + 300) / 600 = 10.50.
        $this->assertSame('600.000', $this->stock()->quantityForProduct($husk->id));
        $this->assertSame('6300.000000', $this->stock()->valueForProduct($husk->id));
        $this->assertSame('10.500000', $this->stock()->weightedAverageCostForProduct($husk->id));

        // No lots for pooled husk.
        $this->assertNull($flow['husk_grn']->lines->first()->lot_id);
        $this->assertNull($flow['dehusking']->outputs->firstWhere('product_id', $husk->id)->lot_id);

        // The chipping run consumed nothing and journaled nothing.
        $this->assertNull($flow['chipping']->inventory_transaction_id);
        $this->assertFalse(JournalEntry::query()
            ->where('source_type', ProductionOrder::class)
            ->where('source_id', $flow['chipping']->id)
            ->exists());

        // KG and litres never mix: 40 KG of 7C and 100 L of 7C are two balances.
        $this->assertSame('40.000', $this->stock()->quantityForProduct($this->product('HUSK-7C')->id));
        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->product('HUSK-7C-L')->id));
        $this->assertSame('100.000', $this->stock()->quantityForProduct($this->product('COCOPEAT-L')->id));
        $this->assertSame('0.000', $this->stock()->quantityForProduct($this->product('COCOPEAT')->id));
    }

    #[Test]
    public function delivering_chips_backflushes_the_husks_they_used_into_cost_of_goods_sold(): void
    {
        $this->produce();

        // 20 KG of 7C at Rs. 300, made from 180 raw husks.
        $delivery = app(PostDelivery::class)->handle($this->draftDelivery([
            ['code' => 'HUSK-7C', 'quantity' => '20.000', 'price' => '300.000000', 'husks' => '180.000'],
        ]));

        $line = $delivery->lines->first();

        $this->assertSame('6000.000000', $line->sales_value);
        // 180 x 10.50, at the pool's weighted average.
        $this->assertSame('1890.000000', $line->backflush_cost);
        $this->assertSame('1890.000000', $line->cost_of_goods_sold);
        $this->assertSame('4110.000000', $line->grossProfit());

        // The pool: 600 − 180 = 420, still at 10.50.
        $husk = $this->product('RAW-HUSK');
        $this->assertSame('420.000', $this->stock()->quantityForProduct($husk->id));
        $this->assertSame('4410.000000', $this->stock()->valueForProduct($husk->id));
        $this->assertSame('20.000', $this->stock()->quantityForProduct($this->product('HUSK-7C')->id));

        // Dr Husk Chip COGS 1,890 / Cr Raw Husk Inventory 1,890. The zero-value chip issue
        // itself writes no line.
        $journal = JournalEntry::query()
            ->where('source_type', Delivery::class)
            ->where('source_id', $delivery->id)
            ->firstOrFail();

        $this->assertCount(2, $journal->lines);
        $this->assertSame('1890.000000', $this->balance('5130'));
        // 300 of labour + 6,000 bought − 1,890 backflushed.
        $this->assertSame('4410.000000', $this->balance('1330'));

        // Inventory and the general ledger agree for raw husk and for chips.
        $this->assertSame($this->stock()->valueForProduct($husk->id), $this->balance('1330'));
        $this->assertSame('0.000000', $this->balance('1340'));
        $this->assertSame('0.000000', $this->balance('1370'));
    }

    #[Test]
    public function a_delivery_that_needs_more_husk_than_the_pool_holds_fails_completely(): void
    {
        $this->produce();

        $draft = $this->draftDelivery([
            ['code' => 'HUSK-7C', 'quantity' => '10.000', 'price' => '300.000000', 'husks' => '601.000'],
        ]);

        $transactions = InventoryTransaction::count();
        $journals = JournalEntry::count();

        try {
            app(PostDelivery::class)->handle($draft);
            $this->fail('Expected the delivery to be refused for want of raw husk.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lines.0.backflush_quantity', $e->errors());
        }

        // Nothing at all: no chips left, no husk left, no journal, still a draft.
        $this->assertSame($transactions, InventoryTransaction::count());
        $this->assertSame($journals, JournalEntry::count());
        $this->assertSame(DeliveryStatus::Draft, $draft->fresh()->status);
        $this->assertSame('40.000', $this->stock()->quantityForProduct($this->product('HUSK-7C')->id));
        $this->assertSame('600.000', $this->stock()->quantityForProduct($this->product('RAW-HUSK')->id));
    }

    #[Test]
    public function sales_on_credit_are_settled_in_part_by_cash_and_the_receivable_stays_exact(): void
    {
        $this->produce();

        $delivery = app(PostDelivery::class)->handle($this->draftDelivery([
            ['code' => 'HUSK-7C', 'quantity' => '20.000', 'price' => '300.000000', 'husks' => '180.000'],
            ['code' => 'COCOPEAT-L', 'quantity' => '50.000', 'price' => '40.000000', 'husks' => '60.000'],
        ]));

        // Cocopeat's revenue and cost land in its own accounts.
        $this->assertSame('630.000000', $this->balance('5170'));

        // Invoice everything delivered: 6,000 + 2,000.
        $invoice = app(SaveSalesInvoice::class)->handle(null, [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-09-15',
            'due_date' => '2026-10-15',
            'lines' => $delivery->lines->map(fn (DeliveryLine $line): array => [
                'delivery_line_id' => $line->id,
                'amount' => $line->sales_value,
            ])->all(),
        ]);
        $invoice = app(PostSalesInvoice::class)->handle($invoice);

        // Revenue, read as the account is normally read: chips 6,000; cocopeat 2,000.
        $this->assertSame('6000.000000', $this->balance('4130'));
        $this->assertSame('2000.000000', $this->balance('4170'));

        // The customer pays 2,500 in cash now; the rest stays on credit.
        $receipt = app(SaveCustomerReceipt::class)->handle([
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-09-16',
            'receipt_account_id' => $this->account('1110')->id,
            'amount' => '2500',
            'payment_method' => PaymentMethod::Cash->value,
            'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => '2500']],
        ]);
        app(PostCustomerReceipt::class)->handle($receipt);

        $receivables = app(AccountsReceivableService::class);
        $invoice = SalesInvoice::findOrFail($invoice->id);

        $this->assertSame('8000.000000', $receivables->invoiceTotal($invoice));
        $this->assertSame('2500.000000', $receivables->receivedAmount($invoice));
        $this->assertSame('5500.000000', $receivables->outstanding($invoice));
        $this->assertSame(SalesInvoiceStatus::PartiallyPaid, $invoice->status);

        // Trade Receivables == what customers still owe; the cash is in the till.
        $this->assertSame('5500.000000', $this->balance('1210'));
        $this->assertSame('2500.000000', $this->balance('1110'));
    }

    #[Test]
    public function suppliers_are_paid_in_part_by_cheque_and_the_payable_stays_exact(): void
    {
        $flow = $this->produce();

        // One supplier invoice clearing both receipts' accruals: 5,000 + 6,000.
        $invoice = app(SaveSupplierInvoice::class)->handle(null, [
            'supplier_id' => $this->supplier->id,
            'supplier_invoice_number' => 'INV-77',
            'invoice_date' => '2026-09-15',
            'due_date' => '2026-10-15',
            'lines' => [
                ['line_type' => SupplierInvoiceLineType::GrniMatch->value, 'goods_receipt_id' => $flow['coconut_grn']->id, 'amount' => '5000'],
                ['line_type' => SupplierInvoiceLineType::GrniMatch->value, 'goods_receipt_id' => $flow['husk_grn']->id, 'amount' => '6000'],
            ],
        ]);
        app(PostSupplierInvoice::class)->handle($invoice);

        // Pay 7,000 by cheque from the bank.
        $payment = app(SaveSupplierPayment::class)->handle([
            'supplier_id' => $this->supplier->id,
            'payment_date' => '2026-09-16',
            'payment_account_id' => $this->account('1120')->id,
            'amount' => '7000',
            'payment_method' => PaymentMethod::Cheque->value,
            'cheque_number' => '000123',
            'cheque_date' => '2026-09-16',
            'cheque_bank' => 'Bank of Ceylon',
            'allocations' => [['supplier_invoice_id' => $invoice->id, 'amount' => '7000']],
        ]);
        $payment = app(PostSupplierPayment::class)->handle($payment);

        $this->assertSame(PaymentMethod::Cheque, $payment->payment_method);
        $this->assertSame('000123', $payment->cheque_number);

        $payables = app(AccountsPayableService::class);
        $invoice = SupplierInvoice::findOrFail($invoice->id);

        $this->assertSame('11000.000000', $payables->invoiceTotal($invoice));
        $this->assertSame('4000.000000', $payables->outstanding($invoice));
        $this->assertSame(SupplierInvoiceStatus::PartiallyPaid, $invoice->status);

        // Trade Payables == invoice outstanding; GRNI fully cleared; the bank paid 7,000.
        $this->assertSame('4000.000000', $this->balance('2110'));
        $this->assertSame('0.000000', $this->balance('2120'));
        $this->assertSame('-7000.000000', $this->balance('1120'));
    }
}
