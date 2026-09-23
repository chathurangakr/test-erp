<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\CloseFiscalPeriod;
use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Payables\CancelSupplierInvoice;
use App\Actions\Payables\PostSupplierInvoice;
use App\Actions\Payables\PostSupplierPayment;
use App\Actions\Payables\ReverseSupplierInvoice;
use App\Actions\Payables\ReverseSupplierPayment;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\Enums\GoodsReceiptStatus;
use App\Enums\SupplierInvoiceStatus;
use App\Enums\SupplierPaymentStatus;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceLine;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\GrniReconciliationService;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Database\Seeders\ProductionRecipeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Accounts payable.
 *
 * Receiving stock accrues `Cr Goods Received Not Invoiced`; the supplier's invoice clears
 * that accrual into `Cr Trade Payables`; paying settles it into `Cr Bank`. The two invariants
 * everything else serves are asserted throughout:
 *
 *   GRNI general-ledger balance  ==  total accrual not yet invoiced
 *   Trade Payables balance       ==  total invoice outstanding
 *
 * A receipt's accrual may be cleared by several suppliers — goods, transport and loading are
 * commonly three different companies — so matching is per-amount and partial.
 */
class AccountsPayableTest extends TestCase
{
    use RefreshDatabase;

    private const CUTOVER = '2026-09-01';

    /** @var array<string, Product> */
    private array $products = [];

    private Supplier $goodsSupplier;

    private Supplier $transportSupplier;

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

        $this->goodsSupplier = Supplier::factory()->create(['code' => 'SUP-GOODS', 'name' => 'Coconut Supplier']);
        $this->transportSupplier = Supplier::factory()->create(['code' => 'SUP-TRANSPORT', 'name' => 'Transport Supplier']);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);

        $this->seed(ProductionRecipeSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
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

    private function payables(): AccountsPayableService
    {
        return app(AccountsPayableService::class);
    }

    private function grni(): GrniReconciliationService
    {
        return app(GrniReconciliationService::class);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /** A posted receipt of 10,000 coconut with 20,000 transport and 5,000 loading: 1,025,000. */
    private function postReceipt(string $quantity = '10000.000', string $price = '100.000000', array $attributes = []): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create(array_merge([
            'supplier_id' => $this->goodsSupplier->id,
            'warehouse_id' => $this->main->id,
            'receipt_date' => '2026-09-05',
            'transport_cost' => '20000.000000',
            'loading_cost' => '5000.000000',
        ], $attributes));

        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'product_id' => $this->products['COCONUT']->id,
            'quantity' => $quantity,
            'unit_price' => $price,
        ]);

        return app(PostGoodsReceipt::class)->handle($receipt->refresh());
    }

    /**
     * @param  list<array{grn?: GoodsReceipt, account?: string, amount: string}>  $lines
     */
    private function draftInvoice(array $lines, ?Supplier $supplier = null, array $attributes = []): SupplierInvoice
    {
        $invoice = SupplierInvoice::factory()->create(array_merge([
            'supplier_id' => ($supplier ?? $this->goodsSupplier)->id,
            'invoice_date' => '2026-09-10',
            'due_date' => '2026-09-25',
        ], $attributes));

        foreach ($lines as $line) {
            if (isset($line['grn'])) {
                SupplierInvoiceLine::factory()->grniMatch($line['grn']->id, $line['amount'])->create([
                    'supplier_invoice_id' => $invoice->id,
                ]);

                continue;
            }

            SupplierInvoiceLine::factory()
                ->directExpense($this->account($line['account'])->id, $line['amount'])
                ->create(['supplier_invoice_id' => $invoice->id, 'description' => $line['description'] ?? null]);
        }

        return $invoice->refresh();
    }

    private function postInvoice(SupplierInvoice $invoice, ?int $userId = null): SupplierInvoice
    {
        return app(PostSupplierInvoice::class)->handle($invoice, $userId);
    }

    /**
     * @param  list<array{invoice: SupplierInvoice, amount: string}>  $allocations
     */
    private function draftPayment(array $allocations, string $amount, ?Supplier $supplier = null, array $attributes = []): SupplierPayment
    {
        $payment = SupplierPayment::factory()->create(array_merge([
            'supplier_id' => ($supplier ?? $this->goodsSupplier)->id,
            'payment_date' => '2026-09-20',
            'payment_account_id' => $this->account('1120')->id,
            'amount' => $amount,
        ], $attributes));

        foreach ($allocations as $allocation) {
            SupplierPaymentAllocation::factory()->create([
                'supplier_payment_id' => $payment->id,
                'supplier_invoice_id' => $allocation['invoice']->id,
                'amount' => $allocation['amount'],
            ]);
        }

        return $payment->refresh();
    }

    private function postPayment(SupplierPayment $payment, ?int $userId = null): SupplierPayment
    {
        return app(PostSupplierPayment::class)->handle($payment, $userId);
    }

    private function amountOn(SupplierInvoice|SupplierPayment $document, string $accountCode, string $column): string
    {
        $accountId = $this->account($accountCode)->id;

        return DecimalMath::cost($document->journalEntry->lines->where('account_id', $accountId)->reduce(
            fn (string $carry, $line): string => DecimalMath::add($carry, DecimalMath::of($line->{$column})),
            DecimalMath::zero(),
        ));
    }

    /** The two invariants this whole module exists to preserve. */
    private function assertLedgersAgree(): void
    {
        $this->assertSame(
            $this->grni()->totalRemaining(),
            $this->balances()->balance($this->account('2120')),
            'GRNI general ledger does not equal the uninvoiced accrual',
        );

        $this->assertSame(
            $this->payables()->totalOutstanding(),
            $this->balances()->balance($this->account('2110')),
            'Trade Payables does not equal the outstanding invoice total',
        );

        $this->assertTrue($this->balances()->trialBalance()['balanced']);
    }

    // ------------------------------------------------------------- configuration

    #[Test]
    public function the_trade_payables_account_is_configured_and_validated(): void
    {
        $settings = AccountingSetting::current();

        $this->assertSame('2110', $settings->tradePayablesAccount->code);
        $this->assertTrue($settings->tradePayablesAccount->is_control_account);
        $this->assertFalse($settings->tradePayablesAccount->acceptsManualPosting());
    }

    #[Test]
    public function the_trade_payables_account_must_be_a_liability(): void
    {
        $this->expectException(ValidationException::class);

        app(UpdateAccountingSettings::class)->handle([
            'trade_payables_account_id' => $this->account('1310')->id,
        ]);
    }

    // ------------------------------------------------------------- the invoice document

    #[Test]
    public function a_draft_invoice_creates_no_journal_and_no_payable(): void
    {
        $receipt = $this->postReceipt();
        $journalsBefore = JournalEntry::count();

        $invoice = $this->draftInvoice([['grn' => $receipt, 'amount' => '1000000.000000']]);

        $this->assertSame(SupplierInvoiceStatus::Draft, $invoice->status);
        $this->assertSame($journalsBefore, JournalEntry::count());
        $this->assertSame('0.000000', $this->payables()->totalOutstanding());

        // The accrual is untouched — a draft has cleared nothing.
        $this->assertSame('1025000.000000', $this->grni()->remaining($receipt));
    }

    #[Test]
    public function a_supplier_cannot_send_the_same_invoice_number_twice(): void
    {
        $this->draftInvoice([['account' => '6400', 'amount' => '100.000000']], attributes: [
            'supplier_invoice_number' => 'INV-001',
        ]);

        $this->expectException(QueryException::class);

        $this->draftInvoice([['account' => '6400', 'amount' => '100.000000']], attributes: [
            // Same number, differently typed — normalisation catches it.
            'supplier_invoice_number' => ' inv-001 ',
        ]);
    }

    #[Test]
    public function two_suppliers_may_use_the_same_invoice_number(): void
    {
        $this->draftInvoice([['account' => '6400', 'amount' => '100.000000']], attributes: [
            'supplier_invoice_number' => 'INV-001',
        ]);

        $second = $this->draftInvoice(
            [['account' => '6400', 'amount' => '100.000000']],
            $this->transportSupplier,
            ['supplier_invoice_number' => 'INV-001'],
        );

        $this->assertSame(2, SupplierInvoice::count());
        $this->assertSame('INV-001', $second->supplier_invoice_number);
    }

    #[Test]
    public function an_inactive_supplier_cannot_be_invoiced(): void
    {
        $invoice = $this->draftInvoice([['account' => '6400', 'amount' => '100.000000']]);
        $this->goodsSupplier->update(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->postInvoice($invoice);
    }

    #[Test]
    public function an_invoice_needs_an_open_period_containing_its_date(): void
    {
        $outside = $this->draftInvoice(
            [['account' => '6400', 'amount' => '100.000000']],
            attributes: ['invoice_date' => '2026-10-05', 'due_date' => '2026-10-20'],
        );

        try {
            $this->postInvoice($outside);
            $this->fail('Expected an invoice outside any open period to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('invoice_date', $e->errors());
        }

        $this->assertSame(SupplierInvoiceStatus::Draft, $outside->fresh()->status);

        // And a closed period blocks it too.
        app(CloseFiscalPeriod::class)->handle($this->period);

        $this->expectException(ValidationException::class);
        $this->postInvoice($this->draftInvoice([['account' => '6400', 'amount' => '100.000000']]));
    }

    #[Test]
    public function a_due_date_before_the_invoice_date_is_refused(): void
    {
        $invoice = $this->draftInvoice([['account' => '6400', 'amount' => '100.000000']], attributes: [
            'invoice_date' => '2026-09-10', 'due_date' => '2026-09-05',
        ]);

        $this->expectException(ValidationException::class);

        $this->postInvoice($invoice);
    }

    // ------------------------------------------------------------- GRNI matching

    #[Test]
    public function a_grni_invoice_debits_grni_and_credits_trade_payables(): void
    {
        $user = User::factory()->create();
        $receipt = $this->postReceipt();

        $invoice = $this->postInvoice(
            $this->draftInvoice([['grn' => $receipt, 'amount' => '1000000.000000']]),
            $user->id,
        );

        $this->assertSame(SupplierInvoiceStatus::Posted, $invoice->status);
        $this->assertSame($user->id, $invoice->posted_by);
        $this->assertNotNull($invoice->posted_at);

        $this->assertSame('1000000.000000', $this->amountOn($invoice, '2120', 'debit'));
        $this->assertSame('1000000.000000', $this->amountOn($invoice, '2110', 'credit'));

        // 1,025,000 accrued, 1,000,000 invoiced.
        $this->assertSame('25000.000000', $this->grni()->remaining($receipt));
        $this->assertSame('1000000.000000', $this->payables()->totalOutstanding());

        $this->assertLedgersAgree();
    }

    /**
     * The point of the whole design: one receipt, three suppliers.
     */
    #[Test]
    public function one_receipt_may_be_cleared_by_several_suppliers(): void
    {
        $receipt = $this->postReceipt();
        $loadingContractor = Supplier::factory()->create(['code' => 'SUP-LOADING']);

        $this->assertSame('1025000.000000', $this->grni()->originalAccrual($receipt));

        $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000000.000000']]));
        $this->assertSame('25000.000000', $this->grni()->remaining($receipt));

        $this->postInvoice($this->draftInvoice(
            [['grn' => $receipt, 'amount' => '20000.000000']],
            $this->transportSupplier,
        ));
        $this->assertSame('5000.000000', $this->grni()->remaining($receipt));

        $this->postInvoice($this->draftInvoice(
            [['grn' => $receipt, 'amount' => '5000.000000']],
            $loadingContractor,
        ));

        // Fully cleared: GRNI is flat, payables carry the whole 1,025,000, and inventory is
        // exactly where the receipt left it.
        $this->assertSame('0.000000', $this->grni()->remaining($receipt));
        $this->assertSame('0.000000', $this->balances()->balance($this->account('2120')));
        $this->assertSame('1025000.000000', $this->balances()->balance($this->account('2110')));
        $this->assertSame('1025000.000000', $this->balances()->balance($this->account('1310')));

        $this->assertLedgersAgree();
    }

    #[Test]
    public function an_invoice_cannot_claim_more_than_the_remaining_accrual(): void
    {
        $receipt = $this->postReceipt();
        $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000000.000000']]));

        $overdraw = $this->draftInvoice([['grn' => $receipt, 'amount' => '25000.000001']]);

        try {
            $this->postInvoice($overdraw);
            $this->fail('Expected the over-allocation to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('still uninvoiced', implode(' ', $e->errors()['lines.0.amount']));
        }

        $this->assertSame(SupplierInvoiceStatus::Draft, $overdraw->fresh()->status);
        $this->assertSame('25000.000000', $this->grni()->remaining($receipt));
    }

    #[Test]
    public function two_lines_on_one_invoice_against_one_receipt_are_checked_together(): void
    {
        $receipt = $this->postReceipt();

        // 1,000,000 + 25,000.000001 exceeds the accrual only when taken together.
        $invoice = $this->draftInvoice([
            ['grn' => $receipt, 'amount' => '1000000.000000'],
            ['grn' => $receipt, 'amount' => '25000.000001'],
        ]);

        $this->expectException(ValidationException::class);

        $this->postInvoice($invoice);
    }

    #[Test]
    public function a_pre_cutover_receipt_cannot_be_invoiced(): void
    {
        // Dated before the cutover, so it raised no accrual.
        $receipt = $this->postReceipt(attributes: ['receipt_date' => '2026-08-20']);

        $this->assertSame('0.000000', $this->grni()->originalAccrual($receipt));
        $this->assertFalse($this->grni()->isMatchable($receipt));

        try {
            $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '100.000000']]));
            $this->fail('Expected a pre-cutover receipt to be unmatchable.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('raised no accounting accrual', implode(' ', $e->errors()['lines.0.goods_receipt_id']));
        }
    }

    #[Test]
    public function a_reversed_receipt_cannot_be_invoiced(): void
    {
        $receipt = $this->postReceipt();
        app(ReverseGoodsReceipt::class)->handle($receipt);

        $this->assertSame('0.000000', $this->grni()->originalAccrual($receipt->fresh()));

        $this->expectException(ValidationException::class);

        $this->postInvoice($this->draftInvoice([['grn' => $receipt->fresh(), 'amount' => '100.000000']]));
    }

    // ------------------------------------------------------------- direct expense

    #[Test]
    public function a_direct_expense_invoice_debits_the_expense_and_credits_payables(): void
    {
        $invoice = $this->postInvoice($this->draftInvoice(
            [['account' => '6400', 'amount' => '50000.000000', 'description' => 'September electricity']],
            $this->transportSupplier,
        ));

        $this->assertSame('50000.000000', $this->amountOn($invoice, '6400', 'debit'));
        $this->assertSame('50000.000000', $this->amountOn($invoice, '2110', 'credit'));
        $this->assertSame('50000.000000', $this->payables()->totalOutstanding());

        // No inventory anywhere near it.
        $this->assertSame(0, InventoryTransaction::count());

        $this->assertLedgersAgree();
    }

    #[Test]
    public function a_direct_expense_cannot_charge_a_control_account(): void
    {
        foreach (['1310', '2120', '2110'] as $code) {
            $invoice = $this->draftInvoice([['account' => $code, 'amount' => '100.000000']]);

            try {
                $this->postInvoice($invoice);
                $this->fail("Expected account {$code} to be refused on a direct expense line.");
            } catch (ValidationException) {
                // expected
            }

            $this->assertSame(SupplierInvoiceStatus::Draft, $invoice->fresh()->status);
        }
    }

    #[Test]
    public function a_direct_expense_cannot_charge_a_group_header(): void
    {
        $this->expectException(ValidationException::class);

        $this->postInvoice($this->draftInvoice([['account' => '6000', 'amount' => '100.000000']]));
    }

    #[Test]
    public function one_invoice_may_mix_a_grni_match_and_a_direct_expense(): void
    {
        $receipt = $this->postReceipt();

        $invoice = $this->postInvoice($this->draftInvoice([
            ['grn' => $receipt, 'amount' => '1000000.000000'],
            ['account' => '6400', 'amount' => '5000.000000'],
        ]));

        $this->assertSame('1000000.000000', $this->amountOn($invoice, '2120', 'debit'));
        $this->assertSame('5000.000000', $this->amountOn($invoice, '6400', 'debit'));
        $this->assertSame('1005000.000000', $this->amountOn($invoice, '2110', 'credit'));

        $this->assertLedgersAgree();
    }

    // ------------------------------------------------------------- no inventory effect

    #[Test]
    public function posting_an_invoice_changes_no_inventory_at_all(): void
    {
        $receipt = $this->postReceipt();
        $stock = app(StockQueryService::class);
        $coconutId = $this->products['COCONUT']->id;

        $before = [
            'quantity' => $stock->quantityForProduct($coconutId),
            'value' => $stock->valueForProduct($coconutId),
            'wac' => $stock->weightedAverageCostForProduct($coconutId),
            'transactions' => InventoryTransaction::count(),
        ];

        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000000.000000']]));
        $this->postPayment($this->draftPayment([['invoice' => $invoice, 'amount' => '400000.000000']], '400000.000000'));

        // The receipt established what the stock is worth; AP only records who is owed.
        $this->assertSame($before['quantity'], $stock->quantityForProduct($coconutId));
        $this->assertSame($before['value'], $stock->valueForProduct($coconutId));
        $this->assertSame($before['wac'], $stock->weightedAverageCostForProduct($coconutId));
        $this->assertSame($before['transactions'], InventoryTransaction::count());
        $this->assertSame('1025000.000000', $this->balances()->balance($this->account('1310')));
    }

    // ------------------------------------------------------------- immutability

    #[Test]
    public function a_posted_invoice_is_immutable_and_cannot_be_posted_twice(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000.000000']]));

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
        $receipt = $this->postReceipt();
        $invoice = $this->draftInvoice([['grn' => $receipt, 'amount' => '1000.000000']]);

        $cancelled = app(CancelSupplierInvoice::class)->handle($invoice);

        $this->assertSame(SupplierInvoiceStatus::Cancelled, $cancelled->status);
        $this->assertSame('1025000.000000', $this->grni()->remaining($receipt));
        $this->assertSame('0.000000', $this->payables()->totalOutstanding());
        $this->assertNotNull(SupplierInvoice::find($invoice->id));
    }

    // ------------------------------------------------------------- invoice reversal

    #[Test]
    public function reversing_an_invoice_reverses_its_journal_and_releases_the_grni(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000000.000000']]));

        $reversed = app(ReverseSupplierInvoice::class)->handle($invoice);

        $this->assertSame(SupplierInvoiceStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversal_journal_entry_id);
        $this->assertNotNull($reversed->reversed_at);

        // The accrual is uninvoiced again, with nothing unwound by hand.
        $this->assertSame('1025000.000000', $this->grni()->remaining($receipt));
        $this->assertSame('1025000.000000', $this->balances()->balance($this->account('2120')));
        $this->assertSame('0.000000', $this->balances()->balance($this->account('2110')));
        $this->assertSame('0.000000', $this->payables()->totalOutstanding());

        // The original lines survive untouched.
        $this->assertCount(1, $reversed->lines()->get());
        $this->assertLedgersAgree();
    }

    #[Test]
    public function an_invoice_cannot_be_reversed_twice(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000.000000']]));
        app(ReverseSupplierInvoice::class)->handle($invoice);

        $this->expectException(ValidationException::class);

        app(ReverseSupplierInvoice::class)->handle($invoice->fresh());
    }

    #[Test]
    public function an_invoice_with_a_live_payment_cannot_be_reversed(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '100000.000000']]));
        $payment = $this->postPayment($this->draftPayment([['invoice' => $invoice, 'amount' => '40000.000000']], '40000.000000'));

        try {
            app(ReverseSupplierInvoice::class)->handle($invoice->fresh());
            $this->fail('Expected the reversal to be blocked while a payment stands.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Reverse the associated supplier payment', implode(' ', $e->errors()['status']));
        }

        // Reverse the payment, and the invoice becomes reversible.
        app(ReverseSupplierPayment::class)->handle($payment);

        $reversed = app(ReverseSupplierInvoice::class)->handle($invoice->fresh());
        $this->assertSame(SupplierInvoiceStatus::Reversed, $reversed->status);
    }

    // ------------------------------------------------------------- GRN reversal guard

    #[Test]
    public function a_receipt_that_has_been_invoiced_cannot_be_reversed(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000000.000000']]));

        try {
            app(ReverseGoodsReceipt::class)->handle($receipt->fresh());
            $this->fail('Expected the receipt reversal to be blocked.');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['status']);
            $this->assertStringContainsString('Reverse the associated supplier invoice', $message);
            $this->assertStringContainsString($invoice->internal_invoice_number, $message);
        }

        // Nothing moved: stock, accrual and payables all stand.
        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->fresh()->status);
        $this->assertSame('10000.000', app(StockQueryService::class)->quantityForProduct($this->products['COCONUT']->id));
        $this->assertLedgersAgree();
    }

    #[Test]
    public function a_receipt_becomes_reversible_once_its_invoices_are_reversed(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000000.000000']]));

        app(ReverseSupplierInvoice::class)->handle($invoice);
        app(ReverseGoodsReceipt::class)->handle($receipt->fresh());

        $this->assertSame(GoodsReceiptStatus::Reversed, $receipt->fresh()->status);
        $this->assertSame('0.000', app(StockQueryService::class)->quantityForProduct($this->products['COCONUT']->id));
        $this->assertSame('0.000000', $this->balances()->balance($this->account('2120')));
        $this->assertLedgersAgree();
    }

    #[Test]
    public function an_uninvoiced_receipt_still_reverses_normally(): void
    {
        $receipt = $this->postReceipt();

        app(ReverseGoodsReceipt::class)->handle($receipt);

        $this->assertSame(GoodsReceiptStatus::Reversed, $receipt->fresh()->status);
        $this->assertSame('0.000000', $this->balances()->balance($this->account('2120')));
    }

    // ------------------------------------------------------------- payments

    #[Test]
    public function a_payment_debits_payables_and_credits_the_bank(): void
    {
        $user = User::factory()->create();
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000000.000000']]));

        $payment = $this->postPayment(
            $this->draftPayment([['invoice' => $invoice, 'amount' => '400000.000000']], '400000.000000'),
            $user->id,
        );

        $this->assertSame(SupplierPaymentStatus::Posted, $payment->status);
        $this->assertSame($user->id, $payment->posted_by);

        $this->assertSame('400000.000000', $this->amountOn($payment, '2110', 'debit'));
        $this->assertSame('400000.000000', $this->amountOn($payment, '1120', 'credit'));

        // The payment never touches GRNI — the invoice already cleared it.
        $this->assertSame('0.000000', $this->amountOn($payment, '2120', 'debit'));
        $this->assertSame('25000.000000', $this->grni()->remaining($receipt));

        $this->assertSame(SupplierInvoiceStatus::PartiallyPaid, $invoice->fresh()->status);
        $this->assertSame('600000.000000', $this->payables()->outstanding($invoice->fresh()));

        $this->assertLedgersAgree();
    }

    #[Test]
    public function paying_the_balance_marks_the_invoice_paid(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '100000.000000']]));

        $this->postPayment($this->draftPayment([['invoice' => $invoice, 'amount' => '40000.000000']], '40000.000000'));
        $this->assertSame(SupplierInvoiceStatus::PartiallyPaid, $invoice->fresh()->status);

        $this->postPayment($this->draftPayment([['invoice' => $invoice->fresh(), 'amount' => '60000.000000']], '60000.000000'));

        $this->assertSame(SupplierInvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('0.000000', $this->payables()->outstanding($invoice->fresh()));
        $this->assertSame('0.000000', $this->balances()->balance($this->account('2110')));

        $this->assertLedgersAgree();
    }

    #[Test]
    public function one_payment_may_settle_several_invoices(): void
    {
        $receipt = $this->postReceipt();
        $first = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '600000.000000']]));
        $second = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '400000.000000']]));

        $this->postPayment($this->draftPayment([
            ['invoice' => $first, 'amount' => '600000.000000'],
            ['invoice' => $second, 'amount' => '100000.000000'],
        ], '700000.000000'));

        $this->assertSame(SupplierInvoiceStatus::Paid, $first->fresh()->status);
        $this->assertSame(SupplierInvoiceStatus::PartiallyPaid, $second->fresh()->status);
        $this->assertSame('300000.000000', $this->payables()->totalOutstanding());

        $this->assertLedgersAgree();
    }

    #[Test]
    public function a_payment_must_be_fully_allocated(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '100000.000000']]));

        $under = $this->draftPayment([['invoice' => $invoice, 'amount' => '40000.000000']], '50000.000000');

        try {
            $this->postPayment($under);
            $this->fail('Expected an unallocated remainder to be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Every rupee must be allocated', implode(' ', $e->errors()['allocations']));
        }

        $this->assertSame(SupplierPaymentStatus::Draft, $under->fresh()->status);
    }

    #[Test]
    public function a_payment_cannot_exceed_what_an_invoice_owes(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '100000.000000']]));

        $this->expectException(ValidationException::class);

        $this->postPayment($this->draftPayment([['invoice' => $invoice, 'amount' => '100000.000001']], '100000.000001'));
    }

    #[Test]
    public function a_payment_cannot_settle_another_suppliers_invoice(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '100000.000000']]));

        $payment = $this->draftPayment(
            [['invoice' => $invoice, 'amount' => '1000.000000']],
            '1000.000000',
            $this->transportSupplier,
        );

        $this->expectException(ValidationException::class);

        $this->postPayment($payment);
    }

    #[Test]
    public function a_payment_account_must_be_an_ordinary_asset_account(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000.000000']]));

        foreach (['1310', '2110', '6400'] as $code) {
            $payment = $this->draftPayment(
                [['invoice' => $invoice, 'amount' => '1000.000000']],
                '1000.000000',
                attributes: ['payment_account_id' => $this->account($code)->id],
            );

            try {
                $this->postPayment($payment);
                $this->fail("Expected account {$code} to be refused as a payment account.");
            } catch (ValidationException) {
                // expected
            }
        }

        // Cash on hand is fine.
        $cash = $this->draftPayment(
            [['invoice' => $invoice, 'amount' => '1000.000000']],
            '1000.000000',
            attributes: ['payment_account_id' => $this->account('1110')->id],
        );

        $this->assertSame(SupplierPaymentStatus::Posted, $this->postPayment($cash)->status);
    }

    #[Test]
    public function a_payment_needs_an_open_period_and_an_active_supplier(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000.000000']]));

        $outside = $this->draftPayment(
            [['invoice' => $invoice, 'amount' => '1000.000000']],
            '1000.000000',
            attributes: ['payment_date' => '2026-10-10'],
        );

        try {
            $this->postPayment($outside);
            $this->fail('Expected a payment outside any open period to be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_date', $e->errors());
        }

        $this->goodsSupplier->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        $this->postPayment($this->draftPayment([['invoice' => $invoice, 'amount' => '1000.000000']], '1000.000000'));
    }

    // ------------------------------------------------------------- payment reversal

    #[Test]
    public function reversing_a_payment_restores_the_outstanding_balance_and_the_status(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '100000.000000']]));
        $payment = $this->postPayment($this->draftPayment([['invoice' => $invoice, 'amount' => '100000.000000']], '100000.000000'));

        $this->assertSame(SupplierInvoiceStatus::Paid, $invoice->fresh()->status);

        $reversed = app(ReverseSupplierPayment::class)->handle($payment);

        $this->assertSame(SupplierPaymentStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversal_journal_entry_id);

        // The invoice owes the money again, and says so.
        $this->assertSame(SupplierInvoiceStatus::Posted, $invoice->fresh()->status);
        $this->assertSame('100000.000000', $this->payables()->outstanding($invoice->fresh()));
        $this->assertSame('100000.000000', $this->balances()->balance($this->account('2110')));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('1120')->id));

        // The allocations survive — nothing is deleted.
        $this->assertCount(1, $reversed->allocations()->get());

        $this->assertLedgersAgree();
    }

    #[Test]
    public function a_payment_cannot_be_reversed_twice(): void
    {
        $receipt = $this->postReceipt();
        $invoice = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000.000000']]));
        $payment = $this->postPayment($this->draftPayment([['invoice' => $invoice, 'amount' => '1000.000000']], '1000.000000'));

        app(ReverseSupplierPayment::class)->handle($payment);

        $this->expectException(ValidationException::class);

        app(ReverseSupplierPayment::class)->handle($payment->fresh());
    }

    // ------------------------------------------------------------- reporting

    #[Test]
    public function the_grni_reconciliation_reports_original_matched_and_remaining(): void
    {
        $receipt = $this->postReceipt();
        $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000000.000000']]));

        $report = $this->grni()->report();

        $this->assertCount(1, $report['rows']);
        $this->assertSame('1025000.000000', $report['rows'][0]['original_accrual']);
        $this->assertSame('1000000.000000', $report['rows'][0]['matched_amount']);
        $this->assertSame('25000.000000', $report['rows'][0]['remaining_amount']);
        $this->assertSame('PARTIALLY_MATCHED', $report['rows'][0]['status']);

        $this->assertSame('1025000.000000', $report['original_total']);
        $this->assertSame('25000.000000', $report['remaining_total']);

        // Clear the rest and it reads FULLY_MATCHED with nothing left.
        $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '25000.000000']], $this->transportSupplier));

        $final = $this->grni()->report();
        $this->assertSame('FULLY_MATCHED', $final['rows'][0]['status']);
        $this->assertSame('0.000000', $final['remaining_total']);
        $this->assertSame('0.000000', $this->balances()->balance($this->account('2120')));
    }

    #[Test]
    public function an_untouched_receipt_reads_as_unmatched(): void
    {
        $this->postReceipt();

        $report = $this->grni()->report();

        $this->assertSame('UNMATCHED', $report['rows'][0]['status']);
        $this->assertSame('0.000000', $report['matched_total']);
    }

    #[Test]
    public function the_aging_report_buckets_by_how_overdue_an_invoice_is(): void
    {
        $receipt = $this->postReceipt();

        // Every invoice sits in the open September period; the aging is taken at the end of
        // December, by which point the due dates are 0, 15, 45, 75 and 120 days past.
        foreach ([
            ['2027-01-31', '10000.000000'],
            ['2026-12-16', '20000.000000'],
            ['2026-11-16', '30000.000000'],
            ['2026-10-17', '40000.000000'],
            ['2026-09-02', '50000.000000'],
        ] as [$due, $amount]) {
            $this->postInvoice($this->draftInvoice(
                [['grn' => $receipt, 'amount' => $amount]],
                attributes: ['invoice_date' => '2026-09-01', 'due_date' => $due],
            ));
        }

        $aging = $this->payables()->aging(Carbon::parse('2026-12-31'));

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
    public function a_reversed_invoice_and_a_reversed_payment_leave_the_aging_correct(): void
    {
        $receipt = $this->postReceipt();
        $kept = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '100000.000000']]));
        $dropped = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '200000.000000']]));

        $payment = $this->postPayment($this->draftPayment([['invoice' => $kept, 'amount' => '40000.000000']], '40000.000000'));

        $this->assertSame('260000.000000', $this->payables()->aging()['totals']['total']);

        // A reversed invoice disappears from the aging entirely.
        app(ReverseSupplierInvoice::class)->handle($dropped);
        $this->assertSame('60000.000000', $this->payables()->aging()['totals']['total']);

        // A reversed payment stops reducing what is owed.
        app(ReverseSupplierPayment::class)->handle($payment);
        $this->assertSame('100000.000000', $this->payables()->aging()['totals']['total']);

        $this->assertLedgersAgree();
    }

    #[Test]
    public function the_supplier_outstanding_total_is_derived_per_supplier(): void
    {
        $receipt = $this->postReceipt();

        $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '600000.000000']]));
        $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '400000.000000']], $this->transportSupplier));

        $this->assertSame('600000.000000', $this->payables()->supplierOutstanding($this->goodsSupplier));
        $this->assertSame('400000.000000', $this->payables()->supplierOutstanding($this->transportSupplier));
        $this->assertSame('1000000.000000', $this->payables()->totalOutstanding());

        $this->assertLedgersAgree();
    }

    // ------------------------------------------------------------- the critical invariants

    #[Test]
    public function the_general_ledger_reconciles_to_grni_and_payables_through_a_whole_cycle(): void
    {
        $loading = Supplier::factory()->create(['code' => 'SUP-LOADING']);
        $receipt = $this->postReceipt();

        $this->assertLedgersAgree();

        $goods = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '1000000.000000']]));
        $this->assertLedgersAgree();

        $transport = $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '20000.000000']], $this->transportSupplier));
        $this->assertLedgersAgree();

        $this->postInvoice($this->draftInvoice([['grn' => $receipt, 'amount' => '5000.000000']], $loading));
        $this->assertLedgersAgree();

        // GRNI fully cleared, payables carrying the whole landed cost.
        $this->assertSame('0.000000', $this->balances()->balance($this->account('2120')));
        $this->assertSame('1025000.000000', $this->balances()->balance($this->account('2110')));

        $this->postPayment($this->draftPayment([['invoice' => $goods, 'amount' => '1000000.000000']], '1000000.000000'));
        $this->assertLedgersAgree();

        $this->postPayment($this->draftPayment(
            [['invoice' => $transport, 'amount' => '20000.000000']],
            '20000.000000',
            $this->transportSupplier,
        ));
        $this->assertLedgersAgree();

        // Only the loading contractor is still owed.
        $this->assertSame('5000.000000', $this->balances()->balance($this->account('2110')));
        $this->assertSame('5000.000000', $this->payables()->totalOutstanding());

        // Inventory never moved through any of it.
        $this->assertSame('1025000.000000', $this->balances()->balance($this->account('1310')));
        $this->assertSame('1025000.000000', app(StockQueryService::class)->valueForProduct($this->products['COCONUT']->id));
    }

    #[Test]
    public function manual_posting_to_the_payables_and_grni_control_accounts_is_still_blocked(): void
    {
        foreach (['2110', '2120'] as $code) {
            $manual = JournalEntry::factory()->create([
                'fiscal_period_id' => $this->period->id,
                'journal_date' => '2026-09-15',
                'source_type' => null,
            ]);
            JournalEntryLine::factory()->debit('100.000000')->create([
                'journal_entry_id' => $manual->id,
                'account_id' => $this->account($code)->id,
            ]);
            JournalEntryLine::factory()->credit('100.000000')->create([
                'journal_entry_id' => $manual->id,
                'account_id' => $this->account('1110')->id,
            ]);

            try {
                app(PostJournalEntry::class)->handle($manual->refresh());
                $this->fail("Expected manual posting to {$code} to be refused.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('lines.0.account_id', $e->errors());
            }

            // Nothing reached the account: control accounts are the system's alone.
            $this->assertSame('0.000000', $this->balances()->netBalance($this->account($code)->id));
        }
    }
}
