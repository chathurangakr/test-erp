<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\CloseFiscalPeriod;
use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Cash\CancelExpenseVoucher;
use App\Actions\Cash\CancelFundTransfer;
use App\Actions\Cash\PostExpenseVoucher;
use App\Actions\Cash\PostFundTransfer;
use App\Actions\Cash\ReverseExpenseVoucher;
use App\Actions\Cash\ReverseFundTransfer;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Payables\PostSupplierInvoice;
use App\Actions\Payables\PostSupplierPayment;
use App\Actions\Receivables\PostCustomerReceipt;
use App\Actions\Receivables\PostSalesInvoice;
use App\Actions\Sales\PostDelivery;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\AccountType;
use App\Enums\CashBankAccountKind;
use App\Enums\ExpenseVoucherStatus;
use App\Enums\FundTransferStatus;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\JournalEntryStatus;
use App\Enums\NormalBalance;
use App\Enums\SupplierInvoiceLineType;
use App\Enums\SupplierPaymentStatus;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\CustomerReceiptAllocation;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\ExpenseVoucher;
use App\Models\ExpenseVoucherLine;
use App\Models\FiscalPeriod;
use App\Models\FundTransfer;
use App\Models\InventoryTransaction;
use App\Models\InventoryTransactionLine;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductAccountMapping;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\UnitOfMeasure;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\AccountingPolicy;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\CashBankBookService;
use App\Support\DecimalMath;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Cash and bank management: configuration, expense vouchers, fund transfers and the book.
 *
 * The invariant everything here serves:
 *
 *   Cash/Bank Book closing balance  ==  that account's general-ledger balance
 *
 * It holds by construction, because both read the same posted journal lines — there is no
 * second source of truth to drift from. The tests verify it anyway, across every kind of
 * document that moves money.
 *
 * The second rule with teeth: an account being an ASSET is no longer enough to pay from or
 * receive into. It must be **configured** as a cash or bank account.
 */
class CashBankManagementTest extends TestCase
{
    use RefreshDatabase;

    private CashBankAccount $cash;

    private CashBankAccount $bank;

    private FiscalPeriod $period;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);

        $this->period = FiscalPeriod::factory()->create([
            'name' => 'September 2026',
            'start_date' => '2026-09-01',
            'end_date' => '2026-09-30',
        ]);

        $this->cash = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        $this->bank = CashBankAccount::whereHas('account', fn ($q) => $q->where('code', '1120'))->firstOrFail();

        // The control accounts AP and AR post to. Configuration, not balances.
        AccountingSetting::query()->updateOrCreate([], [
            'grni_account_id' => $this->account('2120')->id,
            'trade_payables_account_id' => $this->account('2110')->id,
            'trade_receivables_account_id' => $this->account('1210')->id,
        ]);

        // Operational documents journal from the start of the period under test.
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function balances(): AccountingBalanceService
    {
        return app(AccountingBalanceService::class);
    }

    private function book(): CashBankBookService
    {
        return app(CashBankBookService::class);
    }

    /**
     * Put money in an account without using any of the documents under test, so a test of
     * spending is not also a test of receiving.
     */
    private function fund(CashBankAccount $target, string $amount, string $date = '2026-09-01'): JournalEntry
    {
        $entry = JournalEntry::create([
            'journal_number' => 'JV-OPEN-'.$target->id.'-'.substr(md5($amount.$date), 0, 6),
            'journal_date' => $date,
            'fiscal_period_id' => $this->period->id,
            'description' => 'Opening funds',
            'status' => JournalEntryStatus::Draft,
        ]);

        $entry->lines()->create([
            'account_id' => $target->account_id,
            'debit' => $amount,
            'credit' => '0.000000',
        ]);
        $entry->lines()->create([
            'account_id' => $this->account('3100')->id,
            'debit' => '0.000000',
            'credit' => $amount,
        ]);

        return app(PostJournalEntry::class)->handle($entry->refresh());
    }

    /**
     * @param  list<array{0: string, 1: string}>  $lines  [account code, amount]
     */
    private function draftVoucher(array $lines, ?CashBankAccount $from = null, string $date = '2026-09-10'): ExpenseVoucher
    {
        $voucher = ExpenseVoucher::factory()->create([
            'cash_bank_account_id' => ($from ?? $this->bank)->id,
            'voucher_date' => $date,
            'payee_name' => 'Ceylon Electricity Board',
        ]);

        foreach ($lines as [$code, $amount]) {
            ExpenseVoucherLine::factory()->create([
                'expense_voucher_id' => $voucher->id,
                'expense_account_id' => $this->account($code)->id,
                'amount' => $amount,
            ]);
        }

        return $voucher->refresh();
    }

    private function postVoucher(ExpenseVoucher $voucher): ExpenseVoucher
    {
        return app(PostExpenseVoucher::class)->handle($voucher);
    }

    private function draftTransfer(CashBankAccount $from, CashBankAccount $to, string $amount, string $date = '2026-09-12'): FundTransfer
    {
        return FundTransfer::factory()->create([
            'from_cash_bank_account_id' => $from->id,
            'to_cash_bank_account_id' => $to->id,
            'amount' => $amount,
            'transfer_date' => $date,
        ]);
    }

    private function postTransfer(FundTransfer $transfer): FundTransfer
    {
        return app(PostFundTransfer::class)->handle($transfer);
    }

    /** The net movement a journal made on one account. */
    private function movementOn(JournalEntry $entry, Account $account): array
    {
        $line = $entry->lines()->where('account_id', $account->id)->firstOrFail();

        return ['debit' => $line->debit, 'credit' => $line->credit];
    }

    /**
     * The book and the ledger must agree exactly, for every configured account.
     */
    private function assertBookAgreesWithLedger(): void
    {
        foreach (CashBankAccount::with('account')->get() as $configuration) {
            $book = $this->book()->book($configuration);
            $ledger = $this->balances()->netBalance($configuration->account_id);

            $this->assertSame(
                $ledger,
                $book['closing_balance'],
                "The book for {$configuration->display_name} disagrees with its general-ledger balance.",
            );
            $this->assertTrue($this->book()->reconciles($configuration));
        }
    }

    // ------------------------------------------------------------ configuration

    #[Test]
    public function cash_on_hand_is_configured_as_a_cash_account(): void
    {
        $this->assertSame(CashBankAccountKind::Cash, $this->cash->account_kind);
        $this->assertSame('1110', $this->cash->account->code);
        $this->assertTrue($this->cash->is_active);
    }

    #[Test]
    public function the_bank_is_configured_as_a_bank_account(): void
    {
        $this->assertSame(CashBankAccountKind::Bank, $this->bank->account_kind);
        $this->assertSame('1120', $this->bank->account->code);
    }

    #[Test]
    public function the_seeder_configures_only_the_two_official_accounts_and_no_balances(): void
    {
        $this->assertSame(2, CashBankAccount::count());

        // Configuration is not money: seeding it must post nothing.
        $this->assertSame(0, JournalEntry::count());
        $this->assertFalse(Schema::hasColumn('cash_bank_accounts', 'balance'));
        $this->assertFalse(Schema::hasColumn('cash_bank_accounts', 'current_balance'));
    }

    #[Test]
    public function one_general_ledger_account_cannot_be_configured_twice(): void
    {
        $this->expectException(QueryException::class);

        CashBankAccount::create([
            'account_id' => $this->bank->account_id,
            'account_kind' => CashBankAccountKind::Bank,
            'display_name' => 'Bank, again',
        ]);
    }

    #[Test]
    public function a_configured_account_must_be_an_asset(): void
    {
        // Electricity is an expense, not somewhere money is held.
        CashBankAccount::factory()->forAccount($this->account('6400'))->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('requires a Asset account');

        app(AccountingPolicy::class)->assertCashBankAccount($this->account('6400'));
    }

    #[Test]
    public function an_inactive_general_ledger_account_cannot_be_used(): void
    {
        $this->bank->account->forceFill(['is_active' => false])->save();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('inactive');

        app(AccountingPolicy::class)->assertCashBankAccount($this->bank->account->refresh());
    }

    #[Test]
    public function an_inactive_configuration_cannot_be_used(): void
    {
        $this->bank->forceFill(['is_active' => false])->save();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('inactive');

        app(AccountingPolicy::class)->assertCashBankAccount($this->bank->account);
    }

    #[Test]
    public function the_trade_receivables_control_account_cannot_be_a_cash_account(): void
    {
        $receivables = $this->account('1210');
        $this->assertTrue($receivables->is_control_account);

        CashBankAccount::factory()->forAccount($receivables)->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('control account');

        app(AccountingPolicy::class)->assertCashBankAccount($receivables);
    }

    #[Test]
    public function an_inventory_control_account_cannot_be_a_cash_account(): void
    {
        $inventory = $this->account('1310');
        $this->assertTrue($inventory->is_control_account);

        CashBankAccount::factory()->forAccount($inventory)->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('control account');

        app(AccountingPolicy::class)->assertCashBankAccount($inventory);
    }

    #[Test]
    public function a_group_header_cannot_be_a_cash_account(): void
    {
        // 1100 organises the chart; posting to it and its children would double-count.
        $header = $this->account('1100');
        CashBankAccount::factory()->forAccount($header)->create();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not accept postings');

        app(AccountingPolicy::class)->assertCashBankAccount($header);
    }

    #[Test]
    public function an_ordinary_asset_account_is_not_a_cash_account_merely_by_being_an_asset(): void
    {
        // 1400 Other Current Assets: active, asset, not a control account, hand-postable —
        // and still not somewhere money may be paid into or out of.
        $other = $this->account('1400');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not configured as a cash or bank account');

        app(AccountingPolicy::class)->assertCashBankAccount($other);
    }

    #[Test]
    public function a_configuration_with_document_history_cannot_be_deleted(): void
    {
        $this->fund($this->bank, '50000.000000');
        $this->postVoucher($this->draftVoucher([['6400', '10000.000000']]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be deleted');

        $this->bank->delete();
    }

    // ------------------------------------------------------- expense vouchers

    #[Test]
    public function a_draft_expense_voucher_creates_no_journal_and_no_inventory(): void
    {
        $voucher = $this->draftVoucher([['6400', '10000.000000']]);

        $this->assertSame(ExpenseVoucherStatus::Draft, $voucher->status);
        $this->assertNull($voucher->journal_entry_id);
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame('0.000000', $this->balances()->netBalance($this->bank->account_id));
    }

    #[Test]
    public function an_expense_voucher_debits_the_expense_and_credits_the_bank(): void
    {
        $this->fund($this->bank, '500000.000000');

        $voucher = $this->postVoucher($this->draftVoucher([['6400', '10000.000000']]));

        $this->assertSame(ExpenseVoucherStatus::Posted, $voucher->status);
        $this->assertMatchesRegularExpression('/^EXP-\d{8}-\d{6}$/', $voucher->voucher_number);

        $journal = $voucher->journalEntry;
        $this->assertSame(JournalEntryStatus::Posted, $journal->status);
        $this->assertSame(ExpenseVoucher::class, $journal->source_type);
        $this->assertSame($voucher->id, $journal->source_id);

        $this->assertSame(
            ['debit' => '10000.000000', 'credit' => '0.000000'],
            $this->movementOn($journal, $this->account('6400')),
        );
        $this->assertSame(
            ['debit' => '0.000000', 'credit' => '10000.000000'],
            $this->movementOn($journal, $this->account('1120')),
        );

        // 500,000 in, 10,000 out.
        $this->assertSame('490000.000000', $this->balances()->netBalance($this->bank->account_id));
        $this->assertBookAgreesWithLedger();
    }

    #[Test]
    public function a_multi_line_expense_voucher_posts_one_balanced_journal(): void
    {
        $this->fund($this->bank, '500000.000000');

        $voucher = $this->postVoucher($this->draftVoucher([
            ['6400', '30000.000000'],
            ['6500', '15000.000000'],
            ['6600', '5000.000000'],
        ]));

        $journal = $voucher->journalEntry()->with('lines')->first();

        // Three debits and exactly one credit — not one journal per line.
        $this->assertCount(4, $journal->lines);
        $this->assertSame('30000.000000', $this->movementOn($journal, $this->account('6400'))['debit']);
        $this->assertSame('15000.000000', $this->movementOn($journal, $this->account('6500'))['debit']);
        $this->assertSame('5000.000000', $this->movementOn($journal, $this->account('6600'))['debit']);
        $this->assertSame('50000.000000', $this->movementOn($journal, $this->account('1120'))['credit']);

        $this->assertSame('50000.000000', $voucher->load('lines')->total());

        $trial = $this->balances()->trialBalance();
        $this->assertTrue($trial['balanced']);
    }

    #[Test]
    public function an_expense_voucher_raises_no_payable_even_when_a_supplier_is_named(): void
    {
        $this->fund($this->bank, '100000.000000');
        $supplier = Supplier::factory()->create(['code' => 'ABC-FUEL', 'name' => 'ABC Fuel Station']);

        $voucher = $this->draftVoucher([['6500', '20000.000000']]);
        $voucher->forceFill(['supplier_id' => $supplier->id])->save();
        $voucher = $this->postVoucher($voucher->refresh());

        // Reporting only: the journal is unchanged and nothing is owed.
        $this->assertSame('ABC Fuel Station', $voucher->payee());
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('2110')->id));
        $this->assertSame(0, SupplierInvoice::count());
        $this->assertSame(0, SupplierPayment::count());
        $this->assertCount(2, $voucher->journalEntry->lines);
    }

    #[Test]
    public function an_expense_voucher_moves_no_inventory(): void
    {
        $this->fund($this->bank, '100000.000000');
        $this->postVoucher($this->draftVoucher([['6400', '10000.000000']]));

        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame(0, InventoryTransactionLine::count());
        // No inventory control account was touched either.
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('1310')->id));
    }

    #[Test]
    public function an_expense_voucher_needs_an_open_fiscal_period(): void
    {
        $this->fund($this->bank, '100000.000000');
        $voucher = $this->draftVoucher([['6400', '10000.000000']], date: '2026-11-15');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no open fiscal period');

        $this->postVoucher($voucher);
    }

    #[Test]
    public function an_expense_voucher_cannot_post_into_a_closed_period(): void
    {
        $this->fund($this->bank, '100000.000000');
        $voucher = $this->draftVoucher([['6400', '10000.000000']]);

        app(CloseFiscalPeriod::class)->handle($this->period);

        $this->expectException(ValidationException::class);

        $this->postVoucher($voucher);
    }

    #[Test]
    public function an_expense_voucher_cannot_be_paid_from_an_unconfigured_account(): void
    {
        // Point the voucher at "Other Current Assets" by deactivating its configuration
        // after the fact — the posting action must still refuse it.
        $this->bank->forceFill(['is_active' => false])->save();
        $voucher = $this->draftVoucher([['6400', '10000.000000']]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('inactive');

        $this->postVoucher($voucher);
    }

    #[Test]
    public function an_expense_line_must_name_an_expense_account(): void
    {
        $this->fund($this->bank, '100000.000000');

        // 1310 is inventory — charging it here would move inventory value with no stock
        // movement behind it.
        $voucher = $this->draftVoucher([['1310', '10000.000000']]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('requires a Expense account');

        $this->postVoucher($voucher);
    }

    #[Test]
    public function an_expense_line_cannot_charge_cost_of_sales(): void
    {
        $this->fund($this->bank, '100000.000000');

        // Cost of sales is derived from stock movements; charging it by hand would put it
        // out of step with the inventory ledger.
        $voucher = $this->draftVoucher([['5110', '10000.000000']]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('requires a Expense account');

        $this->postVoucher($voucher);
    }

    #[Test]
    public function an_expense_line_cannot_charge_revenue_or_equity(): void
    {
        $this->fund($this->bank, '100000.000000');

        foreach (['4110', '3100'] as $code) {
            $voucher = $this->draftVoucher([[$code, '10000.000000']]);

            try {
                $this->postVoucher($voucher);
                $this->fail("Account {$code} should not be chargeable on an expense voucher.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString('Expense account', $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_zero_expense_line_is_rejected(): void
    {
        $this->fund($this->bank, '100000.000000');
        $voucher = $this->draftVoucher([['6400', '0.000000']]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('greater than zero');

        $this->postVoucher($voucher);
    }

    #[Test]
    public function a_negative_expense_line_is_rejected(): void
    {
        $this->fund($this->bank, '100000.000000');
        $voucher = $this->draftVoucher([['6400', '-5000.000000']]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('greater than zero');

        $this->postVoucher($voucher);
    }

    #[Test]
    public function a_voucher_with_no_lines_is_rejected(): void
    {
        $this->fund($this->bank, '100000.000000');
        $voucher = $this->draftVoucher([]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('at least one expense');

        $this->postVoucher($voucher);
    }

    #[Test]
    public function a_posted_expense_voucher_is_immutable(): void
    {
        $this->fund($this->bank, '100000.000000');
        $voucher = $this->postVoucher($this->draftVoucher([['6400', '10000.000000']]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $voucher->update(['description' => 'Changed my mind']);
    }

    #[Test]
    public function an_expense_voucher_cannot_be_posted_twice(): void
    {
        $this->fund($this->bank, '100000.000000');
        $voucher = $this->postVoucher($this->draftVoucher([['6400', '10000.000000']]));

        $this->expectException(ValidationException::class);

        $this->postVoucher($voucher->refresh());
    }

    #[Test]
    public function reversing_an_expense_voucher_mirrors_it_exactly(): void
    {
        $this->fund($this->bank, '500000.000000');
        $voucher = $this->postVoucher($this->draftVoucher([
            ['6400', '30000.000000'],
            ['6500', '15000.000000'],
        ]));

        $before = $this->balances()->netBalance($this->bank->account_id);
        $this->assertSame('455000.000000', $before);

        $reversed = app(ReverseExpenseVoucher::class)->handle($voucher);

        $this->assertSame(ExpenseVoucherStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversal_journal_entry_id);

        $reversal = $reversed->reversalJournalEntry()->with('lines')->first();
        $this->assertSame('30000.000000', $this->movementOn($reversal, $this->account('6400'))['credit']);
        $this->assertSame('15000.000000', $this->movementOn($reversal, $this->account('6500'))['credit']);
        $this->assertSame('45000.000000', $this->movementOn($reversal, $this->account('1120'))['debit']);

        // The money is back, and the expenses are gone.
        $this->assertSame('500000.000000', $this->balances()->netBalance($this->bank->account_id));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->account('6400')->id));

        // Nothing was deleted.
        $this->assertSame(ExpenseVoucherStatus::Reversed, ExpenseVoucher::findOrFail($voucher->id)->status);
        $this->assertCount(2, $voucher->refresh()->lines);
        $this->assertBookAgreesWithLedger();
    }

    #[Test]
    public function an_expense_voucher_cannot_be_reversed_twice(): void
    {
        $this->fund($this->bank, '100000.000000');
        $voucher = $this->postVoucher($this->draftVoucher([['6400', '10000.000000']]));
        app(ReverseExpenseVoucher::class)->handle($voucher);

        // Two independent guards refuse this: the status is no longer POSTED, and the
        // reversal pointer is already set. The status one is simply reached first.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is Reversed');

        app(ReverseExpenseVoucher::class)->handle($voucher->refresh());
    }

    #[Test]
    public function a_cancelled_draft_voucher_creates_no_journal(): void
    {
        $voucher = $this->draftVoucher([['6400', '10000.000000']]);
        $cancelled = app(CancelExpenseVoucher::class)->handle($voucher);

        $this->assertSame(ExpenseVoucherStatus::Cancelled, $cancelled->status);
        $this->assertNull($cancelled->journal_entry_id);
        $this->assertSame(0, JournalEntry::count());

        // And it is still on the record.
        $this->assertNotNull(ExpenseVoucher::find($voucher->id));
    }

    #[Test]
    public function a_posted_voucher_cannot_be_cancelled(): void
    {
        $this->fund($this->bank, '100000.000000');
        $voucher = $this->postVoucher($this->draftVoucher([['6400', '10000.000000']]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must be reversed');

        app(CancelExpenseVoucher::class)->handle($voucher->refresh());
    }

    // --------------------------------------------------------- fund transfers

    #[Test]
    public function a_draft_fund_transfer_creates_no_journal(): void
    {
        $transfer = $this->draftTransfer($this->cash, $this->bank, '100000.000000');

        $this->assertSame(FundTransferStatus::Draft, $transfer->status);
        $this->assertSame(0, JournalEntry::count());
    }

    #[Test]
    public function cash_to_bank_debits_the_bank_and_credits_cash(): void
    {
        $this->fund($this->cash, '300000.000000');

        $transfer = $this->postTransfer($this->draftTransfer($this->cash, $this->bank, '100000.000000'));

        $this->assertSame(FundTransferStatus::Posted, $transfer->status);
        $this->assertMatchesRegularExpression('/^TRF-\d{8}-\d{6}$/', $transfer->transfer_number);

        $journal = $transfer->journalEntry()->with('lines')->first();
        $this->assertCount(2, $journal->lines);
        $this->assertSame('100000.000000', $this->movementOn($journal, $this->account('1120'))['debit']);
        $this->assertSame('100000.000000', $this->movementOn($journal, $this->account('1110'))['credit']);

        $this->assertSame('200000.000000', $this->balances()->netBalance($this->cash->account_id));
        $this->assertSame('100000.000000', $this->balances()->netBalance($this->bank->account_id));
        $this->assertBookAgreesWithLedger();
    }

    #[Test]
    public function bank_to_cash_works_in_the_other_direction(): void
    {
        $this->fund($this->bank, '300000.000000');

        $transfer = $this->postTransfer($this->draftTransfer($this->bank, $this->cash, '75000.000000'));
        $journal = $transfer->journalEntry;

        $this->assertSame('75000.000000', $this->movementOn($journal, $this->account('1110'))['debit']);
        $this->assertSame('75000.000000', $this->movementOn($journal, $this->account('1120'))['credit']);
        $this->assertSame('75000.000000', $this->balances()->netBalance($this->cash->account_id));
        $this->assertSame('225000.000000', $this->balances()->netBalance($this->bank->account_id));
    }

    #[Test]
    public function bank_to_bank_works_when_a_second_bank_is_configured(): void
    {
        $second = Account::create([
            'code' => '1121',
            'name' => 'Commercial Bank',
            'account_type' => AccountType::Asset,
            'normal_balance' => NormalBalance::Debit,
            'is_control_account' => false,
            'allow_manual_posting' => true,
            'is_active' => true,
        ]);
        $secondBank = CashBankAccount::factory()->forAccount($second)->create(['display_name' => 'Commercial Bank']);

        $this->fund($this->bank, '400000.000000');
        $transfer = $this->postTransfer($this->draftTransfer($this->bank, $secondBank, '150000.000000'));

        $this->assertSame('150000.000000', $this->movementOn($transfer->journalEntry, $second)['debit']);
        $this->assertSame('250000.000000', $this->balances()->netBalance($this->bank->account_id));
        $this->assertSame('150000.000000', $this->balances()->netBalance($second->id));
        $this->assertBookAgreesWithLedger();
    }

    #[Test]
    public function a_transfer_to_the_same_account_is_rejected(): void
    {
        $this->fund($this->bank, '100000.000000');
        $transfer = $this->draftTransfer($this->bank, $this->bank, '50000.000000');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('two different cash or bank accounts');

        $this->postTransfer($transfer);
    }

    #[Test]
    public function a_zero_transfer_is_rejected(): void
    {
        $transfer = $this->draftTransfer($this->cash, $this->bank, '0.000000');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('greater than zero');

        $this->postTransfer($transfer);
    }

    #[Test]
    public function a_negative_transfer_is_rejected(): void
    {
        $transfer = $this->draftTransfer($this->cash, $this->bank, '-50000.000000');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('greater than zero');

        $this->postTransfer($transfer);
    }

    #[Test]
    public function an_inactive_source_account_is_rejected(): void
    {
        $this->cash->forceFill(['is_active' => false])->save();
        $transfer = $this->draftTransfer($this->cash, $this->bank, '50000.000000');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('inactive');

        $this->postTransfer($transfer);
    }

    #[Test]
    public function an_inactive_destination_account_is_rejected(): void
    {
        $this->bank->forceFill(['is_active' => false])->save();
        $transfer = $this->draftTransfer($this->cash, $this->bank, '50000.000000');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('inactive');

        $this->postTransfer($transfer);
    }

    #[Test]
    public function a_transfer_needs_an_open_fiscal_period(): void
    {
        $transfer = $this->draftTransfer($this->cash, $this->bank, '50000.000000', '2026-12-25');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no open fiscal period');

        $this->postTransfer($transfer);
    }

    #[Test]
    public function a_transfer_touches_neither_profit_nor_loss(): void
    {
        $this->fund($this->cash, '300000.000000');
        $this->postTransfer($this->draftTransfer($this->cash, $this->bank, '100000.000000'));

        foreach ([AccountType::Revenue, AccountType::Expense, AccountType::CostOfSales] as $type) {
            $this->assertSame(
                '0.000000',
                $this->balances()->balanceForType($type),
                "A fund transfer must not touch {$type->label()}.",
            );
        }
    }

    #[Test]
    public function a_transfer_leaves_combined_cash_and_bank_unchanged(): void
    {
        $this->fund($this->cash, '300000.000000');

        $before = $this->book()->combinedBalance();
        $this->postTransfer($this->draftTransfer($this->cash, $this->bank, '175000.000000'));
        $after = $this->book()->combinedBalance();

        $this->assertSame('300000.000000', $before);
        $this->assertSame($before, $after);
    }

    #[Test]
    public function a_transfer_moves_no_inventory(): void
    {
        $this->fund($this->cash, '300000.000000');
        $this->postTransfer($this->draftTransfer($this->cash, $this->bank, '100000.000000'));

        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame(0, InventoryTransactionLine::count());
    }

    #[Test]
    public function reversing_a_transfer_puts_the_money_back(): void
    {
        $this->fund($this->cash, '300000.000000');
        $transfer = $this->postTransfer($this->draftTransfer($this->cash, $this->bank, '100000.000000'));

        $reversed = app(ReverseFundTransfer::class)->handle($transfer);

        $this->assertSame(FundTransferStatus::Reversed, $reversed->status);

        $reversal = $reversed->reversalJournalEntry()->with('lines')->first();
        $this->assertSame('100000.000000', $this->movementOn($reversal, $this->account('1110'))['debit']);
        $this->assertSame('100000.000000', $this->movementOn($reversal, $this->account('1120'))['credit']);

        $this->assertSame('300000.000000', $this->balances()->netBalance($this->cash->account_id));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->bank->account_id));
        $this->assertNotNull(FundTransfer::find($transfer->id));
        $this->assertBookAgreesWithLedger();
    }

    #[Test]
    public function a_transfer_cannot_be_reversed_twice(): void
    {
        $this->fund($this->cash, '300000.000000');
        $transfer = $this->postTransfer($this->draftTransfer($this->cash, $this->bank, '100000.000000'));
        app(ReverseFundTransfer::class)->handle($transfer);

        // As above: refused by the status guard before the reversal-pointer guard.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is Reversed');

        app(ReverseFundTransfer::class)->handle($transfer->refresh());
    }

    #[Test]
    public function a_posted_transfer_is_immutable_and_a_draft_one_can_be_cancelled(): void
    {
        $this->fund($this->cash, '300000.000000');
        $posted = $this->postTransfer($this->draftTransfer($this->cash, $this->bank, '100000.000000'));

        $cancelled = app(CancelFundTransfer::class)->handle($this->draftTransfer($this->cash, $this->bank, '5000.000000'));
        $this->assertSame(FundTransferStatus::Cancelled, $cancelled->status);
        $this->assertNull($cancelled->journal_entry_id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('immutable');

        $posted->update(['amount' => '1.000000']);
    }

    // ------------------------------------------------- AP and AR integration

    /**
     * A supplier with one posted invoice for `$amount`, ready to be paid.
     */
    private function payableInvoice(string $amount): SupplierInvoice
    {
        $supplier = Supplier::factory()->create();

        $invoice = SupplierInvoice::factory()->create([
            'supplier_id' => $supplier->id,
            'invoice_date' => '2026-09-05',
            'due_date' => '2026-09-25',
        ]);

        $invoice->lines()->create([
            'line_type' => SupplierInvoiceLineType::DirectExpense,
            'debit_account_id' => $this->account('6100')->id,
            'description' => 'Transport',
            'amount' => $amount,
        ]);

        // Posted through the real action, so Trade Payables is genuinely credited and the
        // payment has something in the general ledger to clear.
        return app(PostSupplierInvoice::class)->handle($invoice->refresh());
    }

    /**
     * A customer with one posted sales invoice for `$amount`, ready to be received.
     *
     * Built on a genuine posted delivery, because an invoice line always bills a delivery
     * line — that rule is not relaxed for a test.
     */
    private function receivableInvoice(string $amount): SalesInvoice
    {
        $nos = UnitOfMeasure::query()->firstOrCreate(['code' => 'NOS'], ['name' => 'Numbers', 'decimal_places' => 0]);

        $product = Product::factory()->create(['code' => 'COCONUT-'.fake()->unique()->numerify('####'), 'primary_uom_id' => $nos->id]);
        ProductAccountMapping::create([
            'product_id' => $product->id,
            'inventory_account_id' => $this->account('1310')->id,
            'cogs_account_id' => $this->account('5110')->id,
            'revenue_account_id' => $this->account('4110')->id,
        ]);

        $warehouse = Warehouse::query()->where('is_default', true)->first() ?? Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $customer = Customer::factory()->create();
        $lot = app(CreateInventoryLot::class)->handle($product);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $product->id,
                warehouseId: $warehouse->id,
                direction: InventoryDirection::In,
                quantity: '1000.000',
                lotId: $lot->id,
                unitCost: '100.000000',
            ),
        ]);

        $delivery = Delivery::factory()->create([
            'customer_id' => $customer->id,
            'warehouse_id' => $warehouse->id,
            'delivery_date' => '2026-09-03',
        ]);
        DeliveryLine::factory()->create([
            'delivery_id' => $delivery->id,
            'product_id' => $product->id,
            'lot_id' => $lot->id,
            'quantity' => '500.000',
            'unit_price' => DecimalMath::cost(DecimalMath::div($amount, '500')),
        ]);

        $delivery = app(PostDelivery::class)->handle($delivery->refresh());

        $invoice = SalesInvoice::factory()->create([
            'customer_id' => $customer->id,
            'invoice_date' => '2026-09-05',
            'due_date' => '2026-09-25',
        ]);
        $invoice->lines()->create([
            'delivery_line_id' => $delivery->lines->first()->id,
            'product_id' => $product->id,
            'description' => 'Goods',
            'amount' => $amount,
        ]);

        return app(PostSalesInvoice::class)->handle($invoice->refresh());
    }

    #[Test]
    public function a_supplier_payment_requires_a_configured_cash_or_bank_account(): void
    {
        $this->fund($this->bank, '500000.000000');
        $invoice = $this->payableInvoice('60000.000000');

        $payment = SupplierPayment::factory()->create([
            'supplier_id' => $invoice->supplier_id,
            'payment_date' => '2026-09-15',
            // Other Current Assets: an ASSET, active, hand-postable — and not configured.
            'payment_account_id' => $this->account('1400')->id,
            'amount' => '60000.000000',
        ]);
        SupplierPaymentAllocation::factory()->create([
            'supplier_payment_id' => $payment->id,
            'supplier_invoice_id' => $invoice->id,
            'amount' => '60000.000000',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not configured as a cash or bank account');

        app(PostSupplierPayment::class)->handle($payment);
    }

    #[Test]
    public function a_supplier_payment_through_a_configured_account_reaches_the_cash_book(): void
    {
        $this->fund($this->bank, '500000.000000');
        $invoice = $this->payableInvoice('60000.000000');

        $payment = SupplierPayment::factory()->create([
            'supplier_id' => $invoice->supplier_id,
            'payment_date' => '2026-09-15',
            'payment_account_id' => $this->bank->account_id,
            'amount' => '60000.000000',
        ]);
        SupplierPaymentAllocation::factory()->create([
            'supplier_payment_id' => $payment->id,
            'supplier_invoice_id' => $invoice->id,
            'amount' => '60000.000000',
        ]);

        $posted = app(PostSupplierPayment::class)->handle($payment);
        $this->assertSame(SupplierPaymentStatus::Posted, $posted->status);

        $book = $this->book()->book($this->bank);
        $row = collect($book['rows'])->firstWhere('source_number', $posted->payment_number);

        $this->assertNotNull($row, 'The supplier payment must appear in the bank book.');
        $this->assertSame('Supplier payment', $row['source_label']);
        $this->assertSame('60000.000000', $row['money_out']);
        $this->assertSame('0.000000', $row['money_in']);
        $this->assertSame('440000.000000', $book['closing_balance']);

        // AP still reconciles: the payment cleared the invoice in full.
        $this->assertSame('0.000000', app(AccountsPayableService::class)->outstanding($invoice->refresh()));
        $this->assertSame('0.000000', $this->balances()->balance($this->account('2110')));
        $this->assertBookAgreesWithLedger();
    }

    #[Test]
    public function a_customer_receipt_requires_a_configured_cash_or_bank_account(): void
    {
        $invoice = $this->receivableInvoice('80000.000000');

        $receipt = CustomerReceipt::factory()->create([
            'customer_id' => $invoice->customer_id,
            'receipt_date' => '2026-09-18',
            'receipt_account_id' => $this->account('1400')->id,
            'amount' => '80000.000000',
        ]);
        CustomerReceiptAllocation::factory()->create([
            'customer_receipt_id' => $receipt->id,
            'sales_invoice_id' => $invoice->id,
            'amount' => '80000.000000',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not configured as a cash or bank account');

        app(PostCustomerReceipt::class)->handle($receipt);
    }

    #[Test]
    public function a_customer_receipt_through_a_configured_account_reaches_the_cash_book(): void
    {
        $invoice = $this->receivableInvoice('80000.000000');

        $receipt = CustomerReceipt::factory()->create([
            'customer_id' => $invoice->customer_id,
            'receipt_date' => '2026-09-18',
            'receipt_account_id' => $this->cash->account_id,
            'amount' => '80000.000000',
        ]);
        CustomerReceiptAllocation::factory()->create([
            'customer_receipt_id' => $receipt->id,
            'sales_invoice_id' => $invoice->id,
            'amount' => '80000.000000',
        ]);

        $posted = app(PostCustomerReceipt::class)->handle($receipt);

        $book = $this->book()->book($this->cash);
        $row = collect($book['rows'])->firstWhere('source_number', $posted->receipt_number);

        $this->assertNotNull($row, 'The customer receipt must appear in the cash book.');
        $this->assertSame('Customer receipt', $row['source_label']);
        $this->assertSame('80000.000000', $row['money_in']);
        $this->assertSame('80000.000000', $book['closing_balance']);

        // AR still reconciles: the receipt settled the invoice in full.
        $this->assertSame('0.000000', app(AccountsReceivableService::class)->outstanding($invoice->refresh()));
        $this->assertSame('0.000000', $this->balances()->balance($this->account('1210')));
        $this->assertBookAgreesWithLedger();
    }

    // ------------------------------------------------------- cash / bank book

    #[Test]
    public function the_book_gathers_every_kind_of_document_that_moved_money(): void
    {
        // A manual journal...
        $this->fund($this->bank, '1000000.000000', '2026-09-01');

        // ...a customer receipt in...
        $receivable = $this->receivableInvoice('80000.000000');
        $receipt = CustomerReceipt::factory()->create([
            'customer_id' => $receivable->customer_id,
            'receipt_date' => '2026-09-04',
            'receipt_account_id' => $this->bank->account_id,
            'amount' => '80000.000000',
        ]);
        CustomerReceiptAllocation::factory()->create([
            'customer_receipt_id' => $receipt->id,
            'sales_invoice_id' => $receivable->id,
            'amount' => '80000.000000',
        ]);
        $receipt = app(PostCustomerReceipt::class)->handle($receipt);

        // ...a supplier payment out...
        $payable = $this->payableInvoice('60000.000000');
        $payment = SupplierPayment::factory()->create([
            'supplier_id' => $payable->supplier_id,
            'payment_date' => '2026-09-06',
            'payment_account_id' => $this->bank->account_id,
            'amount' => '60000.000000',
        ]);
        SupplierPaymentAllocation::factory()->create([
            'supplier_payment_id' => $payment->id,
            'supplier_invoice_id' => $payable->id,
            'amount' => '60000.000000',
        ]);
        $payment = app(PostSupplierPayment::class)->handle($payment);

        // ...an expense voucher out...
        $voucher = $this->postVoucher($this->draftVoucher([['6400', '30000.000000']], date: '2026-09-08'));

        // ...and a transfer out.
        $transfer = $this->postTransfer($this->draftTransfer($this->bank, $this->cash, '50000.000000', '2026-09-09'));

        $book = $this->book()->book($this->bank);
        $labels = collect($book['rows'])->pluck('source_label')->all();

        $this->assertSame(
            ['Manual journal', 'Customer receipt', 'Supplier payment', 'Expense voucher', 'Fund transfer'],
            $labels,
            'Every source must appear, in journal date order.',
        );

        $numbers = collect($book['rows'])->pluck('source_number')->all();
        $this->assertSame(
            [null, $receipt->receipt_number, $payment->payment_number, $voucher->voucher_number, $transfer->transfer_number],
            $numbers,
        );

        // 1,000,000 + 80,000 − 60,000 − 30,000 − 50,000
        $this->assertSame('1080000.000000', $book['money_in_total']);
        $this->assertSame('140000.000000', $book['money_out_total']);
        $this->assertSame('940000.000000', $book['closing_balance']);

        $this->assertBookAgreesWithLedger();
    }

    #[Test]
    public function the_running_balance_walks_the_lines_in_order(): void
    {
        $this->fund($this->bank, '200000.000000', '2026-09-01');
        $this->postVoucher($this->draftVoucher([['6400', '30000.000000']], date: '2026-09-05'));
        $this->postVoucher($this->draftVoucher([['6500', '20000.000000']], date: '2026-09-07'));
        $this->postTransfer($this->draftTransfer($this->cash, $this->bank, '10000.000000', '2026-09-09'));

        $running = collect($this->book()->book($this->bank)['rows'])->pluck('running_balance')->all();

        $this->assertSame(
            ['200000.000000', '170000.000000', '150000.000000', '160000.000000'],
            $running,
        );
    }

    #[Test]
    public function date_filters_narrow_the_book_without_losing_the_opening_balance(): void
    {
        $this->fund($this->bank, '200000.000000', '2026-09-01');
        $this->postVoucher($this->draftVoucher([['6400', '30000.000000']], date: '2026-09-05'));
        $this->postVoucher($this->draftVoucher([['6500', '20000.000000']], date: '2026-09-20'));

        $window = $this->book()->book(
            $this->bank,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-30'),
        );

        // Only the 20th falls in the window; everything before it is the opening balance.
        $this->assertCount(1, $window['rows']);
        $this->assertSame('170000.000000', $window['opening_balance']);
        $this->assertSame('20000.000000', $window['money_out_total']);
        $this->assertSame('150000.000000', $window['closing_balance']);

        // And the full book still ends in the same place.
        $this->assertSame('150000.000000', $this->book()->book($this->bank)['closing_balance']);
    }

    #[Test]
    public function a_reversed_document_appears_in_the_book_with_its_reversal(): void
    {
        $this->fund($this->bank, '200000.000000');
        $voucher = $this->postVoucher($this->draftVoucher([['6400', '30000.000000']]));
        app(ReverseExpenseVoucher::class)->handle($voucher);

        $book = $this->book()->book($this->bank);

        // Three lines: the funding, the payment, and the money coming back. Nothing is
        // hidden and nothing is deleted — they simply sum to the original balance.
        $this->assertCount(3, $book['rows']);
        $this->assertSame('200000.000000', $book['closing_balance']);
        $this->assertBookAgreesWithLedger();
    }

    #[Test]
    public function the_summary_totals_cash_and_bank_separately(): void
    {
        $this->fund($this->cash, '120000.000000');
        $this->fund($this->bank, '880000.000000');

        $summary = $this->book()->summary();

        $this->assertCount(2, $summary['rows']);
        $this->assertSame('120000.000000', $summary['cash_total']);
        $this->assertSame('880000.000000', $summary['bank_total']);
        $this->assertSame('1000000.000000', $summary['combined_total']);

        // Each row reports a derived balance, and the row order follows the chart.
        $this->assertSame(['1110', '1120'], collect($summary['rows'])->pluck('account_code')->all());
        $this->assertSame('120000.000000', $summary['rows'][0]['balance']);
    }

    #[Test]
    public function the_trial_balance_stays_balanced_through_every_cash_document(): void
    {
        $this->fund($this->bank, '500000.000000');
        $this->postVoucher($this->draftVoucher([['6400', '30000.000000'], ['6500', '15000.000000']]));
        $this->postTransfer($this->draftTransfer($this->bank, $this->cash, '100000.000000'));

        $reversible = $this->postVoucher($this->draftVoucher([['6600', '7500.000000']]));
        app(ReverseExpenseVoucher::class)->handle($reversible);

        $trial = $this->balances()->trialBalance();

        $this->assertTrue($trial['balanced']);
        $this->assertSame($trial['debit_total'], $trial['credit_total']);
        $this->assertBookAgreesWithLedger();
    }

    #[Test]
    public function nothing_in_this_phase_touches_inventory(): void
    {
        $this->fund($this->bank, '500000.000000');
        $this->postVoucher($this->draftVoucher([['6400', '30000.000000']]));
        $this->postTransfer($this->draftTransfer($this->bank, $this->cash, '100000.000000'));

        $this->assertSame(0, InventoryTransaction::count());
        $this->assertSame(0, InventoryTransactionLine::count());

        foreach (['1310', '1320', '1330', '1340', '1350', '1360'] as $code) {
            $this->assertSame('0.000000', $this->balances()->netBalance($this->account($code)->id));
        }
    }

    #[Test]
    public function an_expense_voucher_may_overdraw_an_account_because_no_policy_says_otherwise(): void
    {
        // Documented as TBD in docs/ACCOUNTING_RULES.md §23: whether cash may go negative,
        // whether banks may overdraw, and whether a limit is configurable are unanswered
        // business questions. This test records the CURRENT behaviour so that introducing a
        // policy later is a deliberate, visible change rather than a silent one.
        $this->postVoucher($this->draftVoucher([['6400', '10000.000000']]));

        $this->assertSame('-10000.000000', $this->balances()->netBalance($this->bank->account_id));
        $this->assertBookAgreesWithLedger();
    }
}
