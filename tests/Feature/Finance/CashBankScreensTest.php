<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\PostJournalEntry;
use App\Enums\CashBankAccountKind;
use App\Enums\ExpenseVoucherStatus;
use App\Enums\FundTransferStatus;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\ExpenseVoucher;
use App\Models\FiscalPeriod;
use App\Models\FundTransfer;
use App\Models\JournalEntry;
use App\Models\User;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cash and bank screens.
 *
 * The assertions that matter most are the negative ones: no destroy route anywhere, no way to
 * type a balance, and no account offered that has not been configured as cash or bank.
 */
class CashBankScreensTest extends TestCase
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
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    /** Fund an account with a manual journal, so the book has something to show. */
    private function fund(CashBankAccount $target, string $amount, string $date = '2026-09-02'): void
    {
        $entry = JournalEntry::create([
            'journal_number' => 'JV-'.$target->id.'-'.substr(md5($amount.$date), 0, 8),
            'journal_date' => $date,
            'fiscal_period_id' => $this->period->id,
            'description' => 'Opening funds',
            'status' => JournalEntryStatus::Draft,
        ]);
        $entry->lines()->create(['account_id' => $target->account_id, 'debit' => $amount, 'credit' => '0.000000']);
        $entry->lines()->create(['account_id' => $this->account('3100')->id, 'debit' => '0.000000', 'credit' => $amount]);

        app(PostJournalEntry::class)->handle($entry->refresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function voucherPayload(array $extra = []): array
    {
        return array_merge([
            'voucher_date' => '2026-09-10',
            'cash_bank_account_id' => $this->bank->id,
            'payee_name' => 'Ceylon Electricity Board',
            'reference' => 'BILL-77',
            'lines' => [
                ['expense_account_id' => $this->account('6400')->id, 'description' => 'September', 'amount' => '30000.000000'],
                ['expense_account_id' => $this->account('6500')->id, 'description' => 'Diesel', 'amount' => '15000.000000'],
            ],
        ], $extra);
    }

    #[Test]
    public function a_guest_cannot_reach_any_cash_or_bank_screen(): void
    {
        $voucher = ExpenseVoucher::factory()->create(['cash_bank_account_id' => $this->bank->id]);
        $transfer = FundTransfer::factory()->create([
            'from_cash_bank_account_id' => $this->cash->id,
            'to_cash_bank_account_id' => $this->bank->id,
        ]);

        $this->get(route('cash-bank-accounts.index'))->assertRedirect(route('login'));
        $this->get(route('cash-bank-accounts.create'))->assertRedirect(route('login'));
        $this->get(route('cash-bank-accounts.edit', $this->bank))->assertRedirect(route('login'));
        $this->get(route('cash-bank-book.index'))->assertRedirect(route('login'));

        $this->get(route('expense-vouchers.index'))->assertRedirect(route('login'));
        $this->get(route('expense-vouchers.create'))->assertRedirect(route('login'));
        $this->get(route('expense-vouchers.show', $voucher))->assertRedirect(route('login'));
        $this->patch(route('expense-vouchers.post', $voucher))->assertRedirect(route('login'));

        $this->get(route('fund-transfers.index'))->assertRedirect(route('login'));
        $this->get(route('fund-transfers.create'))->assertRedirect(route('login'));
        $this->patch(route('fund-transfers.reverse', $transfer))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_can_reach_every_cash_and_bank_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('cash-bank-accounts.index'))->assertOk();
        $this->actingAs($user)->get(route('cash-bank-accounts.create'))->assertOk();
        $this->actingAs($user)->get(route('cash-bank-accounts.edit', $this->bank))->assertOk();
        $this->actingAs($user)->get(route('cash-bank-book.index'))->assertOk();
        $this->actingAs($user)->get(route('expense-vouchers.index'))->assertOk();
        $this->actingAs($user)->get(route('expense-vouchers.create'))->assertOk();
        $this->actingAs($user)->get(route('fund-transfers.index'))->assertOk();
        $this->actingAs($user)->get(route('fund-transfers.create'))->assertOk();
    }

    #[Test]
    public function there_are_no_destroy_routes_in_cash_and_bank(): void
    {
        foreach (['cash-bank-accounts.destroy', 'expense-vouchers.destroy', 'fund-transfers.destroy'] as $name) {
            $this->assertFalse(app('router')->has($name), "{$name} must not exist — cash history is permanent.");
        }
    }

    #[Test]
    public function the_accounts_screen_reports_derived_balances_and_totals(): void
    {
        $user = User::factory()->create();
        $this->fund($this->cash, '120000.000000');
        $this->fund($this->bank, '880000.000000');

        $this->actingAs($user)
            ->get(route('cash-bank-accounts.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/cash-bank-accounts/Index')
                ->has('summary.rows', 2)
                ->where('summary.rows.0.account_code', '1110')
                ->where('summary.rows.0.balance', '120000.000000')
                ->where('summary.rows.1.balance', '880000.000000')
                ->where('summary.cash_total', '120000.000000')
                ->where('summary.bank_total', '880000.000000')
                ->where('summary.combined_total', '1000000.000000'));
    }

    #[Test]
    public function the_configuration_screen_offers_only_eligible_unconfigured_accounts(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('cash-bank-accounts.create'));
        $codes = collect($response->viewData('page')['props']['availableAccounts'])->pluck('code')->all();

        // Already configured.
        $this->assertNotContains('1110', $codes);
        $this->assertNotContains('1120', $codes);
        // Control accounts and group headers are never eligible.
        $this->assertNotContains('1210', $codes);
        $this->assertNotContains('1310', $codes);
        $this->assertNotContains('1100', $codes);
        // An ordinary asset account is.
        $this->assertContains('1400', $codes);
    }

    #[Test]
    public function an_account_can_be_configured_and_edited_through_the_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('cash-bank-accounts.store'), [
            'account_id' => $this->account('1400')->id,
            'account_kind' => 'BANK',
            'display_name' => 'Commercial Bank',
            'bank_name' => 'Commercial Bank PLC',
            'branch_name' => 'Kurunegala',
            'account_number' => '1234567890',
        ])->assertSessionHasNoErrors();

        $configured = CashBankAccount::where('display_name', 'Commercial Bank')->firstOrFail();
        $this->assertSame(CashBankAccountKind::Bank, $configured->account_kind);
        $this->assertTrue($configured->is_active);

        $this->actingAs($user)->put(route('cash-bank-accounts.update', $configured), [
            'account_kind' => 'BANK',
            'display_name' => 'Commercial Bank — Kurunegala',
            'is_active' => false,
        ])->assertSessionHasNoErrors();

        $configured->refresh();
        $this->assertSame('Commercial Bank — Kurunegala', $configured->display_name);
        $this->assertFalse($configured->is_active);
        // The underlying ledger account is not editable and did not move.
        $this->assertSame($this->account('1400')->id, $configured->account_id);
    }

    #[Test]
    public function a_control_account_is_refused_by_the_configuration_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('cash-bank-accounts.store'), [
            'account_id' => $this->account('1210')->id,
            'account_kind' => 'BANK',
            'display_name' => 'Receivables, somehow',
        ])->assertSessionHasErrors('account_id');

        $this->assertSame(2, CashBankAccount::count());
    }

    #[Test]
    public function one_account_cannot_be_configured_twice_through_the_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('cash-bank-accounts.store'), [
            'account_id' => $this->bank->account_id,
            'account_kind' => 'BANK',
            'display_name' => 'Bank, again',
        ])->assertSessionHasErrors('account_id');
    }

    #[Test]
    public function a_voucher_can_be_created_and_posted_through_the_screen(): void
    {
        $user = User::factory()->create();
        $this->fund($this->bank, '500000.000000');

        $this->actingAs($user)
            ->post(route('expense-vouchers.store'), $this->voucherPayload())
            ->assertSessionHasNoErrors();

        $voucher = ExpenseVoucher::firstOrFail();
        $this->assertMatchesRegularExpression('/^EXP-\d{8}-\d{6}$/', $voucher->voucher_number);
        $this->assertSame(ExpenseVoucherStatus::Draft, $voucher->status);
        $this->assertCount(2, $voucher->lines);
        $this->assertSame(0, JournalEntry::where('source_type', ExpenseVoucher::class)->count());

        $this->actingAs($user)
            ->patch(route('expense-vouchers.post', $voucher))
            ->assertSessionHasNoErrors();

        $this->assertSame(ExpenseVoucherStatus::Posted, $voucher->fresh()->status);
    }

    #[Test]
    public function the_voucher_screen_refuses_an_account_that_cannot_be_charged(): void
    {
        $user = User::factory()->create();

        // Inventory, cost of sales and revenue are all refused before anything is written.
        foreach (['1310', '5110', '4110'] as $code) {
            $this->actingAs($user)
                ->post(route('expense-vouchers.store'), $this->voucherPayload([
                    'lines' => [['expense_account_id' => $this->account($code)->id, 'amount' => '1000.000000']],
                ]))
                ->assertSessionHasErrors('lines.0.expense_account_id');
        }

        $this->assertSame(0, ExpenseVoucher::count());
    }

    #[Test]
    public function the_voucher_screen_refuses_a_zero_line_and_an_empty_voucher(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('expense-vouchers.store'), $this->voucherPayload([
                'lines' => [['expense_account_id' => $this->account('6400')->id, 'amount' => '0']],
            ]))
            ->assertSessionHasErrors('lines.0.amount');

        $this->actingAs($user)
            ->post(route('expense-vouchers.store'), $this->voucherPayload(['lines' => []]))
            ->assertSessionHasErrors('lines');

        $this->assertSame(0, ExpenseVoucher::count());
    }

    #[Test]
    public function a_draft_voucher_can_be_edited_but_a_posted_one_cannot(): void
    {
        $user = User::factory()->create();
        $this->fund($this->bank, '500000.000000');

        $this->actingAs($user)->post(route('expense-vouchers.store'), $this->voucherPayload());
        $voucher = ExpenseVoucher::firstOrFail();

        $this->actingAs($user)->get(route('expense-vouchers.edit', $voucher))->assertOk();

        $this->actingAs($user)
            ->put(route('expense-vouchers.update', $voucher), $this->voucherPayload([
                'lines' => [['expense_account_id' => $this->account('6600')->id, 'amount' => '9000.000000']],
            ]))
            ->assertSessionHasNoErrors();

        $voucher->refresh()->load('lines');
        $this->assertCount(1, $voucher->lines);
        $this->assertSame('9000.000000', $voucher->total());

        $this->actingAs($user)->patch(route('expense-vouchers.post', $voucher));

        $this->actingAs($user)
            ->get(route('expense-vouchers.edit', $voucher->fresh()))
            ->assertSessionHasErrors('status');
    }

    #[Test]
    public function the_voucher_show_screen_reports_its_journal(): void
    {
        $user = User::factory()->create();
        $this->fund($this->bank, '500000.000000');

        $this->actingAs($user)->post(route('expense-vouchers.store'), $this->voucherPayload());
        $voucher = ExpenseVoucher::firstOrFail();
        $this->actingAs($user)->patch(route('expense-vouchers.post', $voucher));

        $this->actingAs($user)
            ->get(route('expense-vouchers.show', $voucher))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/expense-vouchers/Show')
                ->where('voucher.status', 'POSTED')
                ->where('voucher.total', '45000.000000')
                ->where('voucher.can_edit', false)
                ->where('voucher.can_reverse', true)
                ->has('voucher.lines', 2)
                // Two debits and one credit.
                ->has('voucher.journal.lines', 3));
    }

    #[Test]
    public function a_voucher_can_be_reversed_and_a_draft_cancelled_through_the_screen(): void
    {
        $user = User::factory()->create();
        $this->fund($this->bank, '500000.000000');

        $this->actingAs($user)->post(route('expense-vouchers.store'), $this->voucherPayload());
        $posted = ExpenseVoucher::firstOrFail();
        $this->actingAs($user)->patch(route('expense-vouchers.post', $posted));
        $this->actingAs($user)->patch(route('expense-vouchers.reverse', $posted))->assertSessionHasNoErrors();
        $this->assertSame(ExpenseVoucherStatus::Reversed, $posted->fresh()->status);

        $this->actingAs($user)->post(route('expense-vouchers.store'), $this->voucherPayload());
        $draft = ExpenseVoucher::orderByDesc('id')->firstOrFail();
        $this->actingAs($user)->patch(route('expense-vouchers.cancel', $draft))->assertSessionHasNoErrors();
        $this->assertSame(ExpenseVoucherStatus::Cancelled, $draft->fresh()->status);
    }

    #[Test]
    public function a_transfer_can_be_created_and_posted_through_the_screen(): void
    {
        $user = User::factory()->create();
        $this->fund($this->cash, '300000.000000');

        $this->actingAs($user)->post(route('fund-transfers.store'), [
            'transfer_date' => '2026-09-12',
            'from_cash_bank_account_id' => $this->cash->id,
            'to_cash_bank_account_id' => $this->bank->id,
            'amount' => '100000.000000',
            'reference' => 'SLIP-9',
        ])->assertSessionHasNoErrors();

        $transfer = FundTransfer::firstOrFail();
        $this->assertMatchesRegularExpression('/^TRF-\d{8}-\d{6}$/', $transfer->transfer_number);

        $this->actingAs($user)->patch(route('fund-transfers.post', $transfer))->assertSessionHasNoErrors();
        $this->assertSame(FundTransferStatus::Posted, $transfer->fresh()->status);

        $this->actingAs($user)
            ->get(route('fund-transfers.show', $transfer))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/fund-transfers/Show')
                ->where('transfer.status', 'POSTED')
                ->where('transfer.from_code', '1110')
                ->where('transfer.to_code', '1120')
                ->has('transfer.journal.lines', 2));
    }

    #[Test]
    public function the_transfer_screen_refuses_the_same_account_and_a_zero_amount(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('fund-transfers.store'), [
            'transfer_date' => '2026-09-12',
            'from_cash_bank_account_id' => $this->bank->id,
            'to_cash_bank_account_id' => $this->bank->id,
            'amount' => '100000.000000',
        ])->assertSessionHasErrors('to_cash_bank_account_id');

        $this->actingAs($user)->post(route('fund-transfers.store'), [
            'transfer_date' => '2026-09-12',
            'from_cash_bank_account_id' => $this->cash->id,
            'to_cash_bank_account_id' => $this->bank->id,
            'amount' => '0',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, FundTransfer::count());
    }

    #[Test]
    public function the_book_screen_reports_movements_and_confirms_it_reconciles(): void
    {
        $user = User::factory()->create();
        $this->fund($this->bank, '500000.000000', '2026-09-02');

        $this->actingAs($user)->post(route('expense-vouchers.store'), $this->voucherPayload());
        $voucher = ExpenseVoucher::firstOrFail();
        $this->actingAs($user)->patch(route('expense-vouchers.post', $voucher));

        $this->actingAs($user)
            ->get(route('cash-bank-book.index', ['cash_bank_account_id' => $this->bank->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/cash-bank-book/Index')
                ->where('selected.account_code', '1120')
                ->has('report.rows', 2)
                ->where('report.rows.0.source_label', 'Manual journal')
                ->where('report.rows.0.money_in', '500000.000000')
                ->where('report.rows.1.source_label', 'Expense voucher')
                ->where('report.rows.1.source_number', $voucher->voucher_number)
                ->where('report.rows.1.money_out', '45000.000000')
                ->where('report.closing_balance', '455000.000000')
                // The point of the whole report.
                ->where('ledger.balance', '455000.000000')
                ->where('ledger.reconciles', true));
    }

    #[Test]
    public function the_book_screen_honours_its_date_filters(): void
    {
        $user = User::factory()->create();
        $this->fund($this->bank, '500000.000000', '2026-09-02');

        $this->actingAs($user)->post(route('expense-vouchers.store'), $this->voucherPayload(['voucher_date' => '2026-09-20']));
        $this->actingAs($user)->patch(route('expense-vouchers.post', ExpenseVoucher::firstOrFail()));

        $this->actingAs($user)
            ->get(route('cash-bank-book.index', [
                'cash_bank_account_id' => $this->bank->id,
                'from' => '2026-09-10',
                'to' => '2026-09-30',
            ]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('report.rows', 1)
                // Everything before the window is folded into the opening balance.
                ->where('report.opening_balance', '500000.000000')
                ->where('report.closing_balance', '455000.000000'));
    }
}
