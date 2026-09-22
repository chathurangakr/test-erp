<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\FiscalPeriodStatus;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\AccountingBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The finance screens.
 *
 * The important assertions here are the negative ones: a guest sees nothing, a manual form
 * cannot reach a control account, and no screen anywhere accepts a balance.
 */
class FinanceScreensTest extends TestCase
{
    use RefreshDatabase;

    private FiscalPeriod $period;

    private Account $electricity;

    private Account $cash;

    private Account $control;

    protected function setUp(): void
    {
        parent::setUp();

        $this->period = FiscalPeriod::factory()->create([
            'name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);

        $this->electricity = Account::factory()->ofType(AccountType::Expense)->create(['code' => '6400', 'name' => 'Electricity']);
        $this->cash = Account::factory()->ofType(AccountType::Asset)->create(['code' => '1110', 'name' => 'Cash on Hand']);
        $this->control = Account::factory()->ofType(AccountType::Asset)->control()->create(['code' => '1210', 'name' => 'Trade Receivables']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'journal_date' => '2026-09-15',
            'description' => 'Electricity paid from cash',
            'lines' => [
                ['account_id' => $this->electricity->id, 'debit' => '10000.000000', 'credit' => '0'],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => '10000.000000'],
            ],
        ], $extra);
    }

    // ------------------------------------------------------------- access

    #[Test]
    public function a_guest_cannot_access_any_finance_screen(): void
    {
        $account = Account::factory()->create();
        $entry = JournalEntry::factory()->create(['fiscal_period_id' => $this->period->id]);

        $this->get(route('accounts.index'))->assertRedirect(route('login'));
        $this->get(route('accounts.create'))->assertRedirect(route('login'));
        $this->get(route('accounts.show', $account))->assertRedirect(route('login'));
        $this->post(route('accounts.store'), [])->assertRedirect(route('login'));

        $this->get(route('journals.index'))->assertRedirect(route('login'));
        $this->get(route('journals.create'))->assertRedirect(route('login'));
        $this->get(route('journals.show', $entry))->assertRedirect(route('login'));
        $this->post(route('journals.store'), [])->assertRedirect(route('login'));
        $this->patch(route('journals.post', $entry))->assertRedirect(route('login'));
        $this->patch(route('journals.reverse', $entry))->assertRedirect(route('login'));

        $this->get(route('general-ledger.index'))->assertRedirect(route('login'));
        $this->get(route('trial-balance.index'))->assertRedirect(route('login'));
        $this->get(route('fiscal-periods.index'))->assertRedirect(route('login'));
        $this->post(route('fiscal-periods.store'), [])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function an_authenticated_user_can_reach_every_finance_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('accounts.index'))->assertOk();
        $this->actingAs($user)->get(route('accounts.create'))->assertOk();
        $this->actingAs($user)->get(route('journals.index'))->assertOk();
        $this->actingAs($user)->get(route('journals.create'))->assertOk();
        $this->actingAs($user)->get(route('general-ledger.index'))->assertOk();
        $this->actingAs($user)->get(route('trial-balance.index'))->assertOk();
        $this->actingAs($user)->get(route('fiscal-periods.index'))->assertOk();
    }

    #[Test]
    public function there_are_no_destroy_routes_in_finance(): void
    {
        foreach (['accounts.destroy', 'journals.destroy', 'fiscal-periods.destroy'] as $name) {
            $this->assertFalse(app('router')->has($name), "{$name} must not exist — accounting history is permanent.");
        }
    }

    // ------------------------------------------------------------- chart of accounts

    #[Test]
    public function the_chart_of_accounts_screen_lists_accounts(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('accounts.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/accounts/Index')
                ->has('accounts.data', 3)
                ->where('currency', 'LKR')
                ->has('types', 6));
    }

    #[Test]
    public function an_account_can_be_created_and_edited_through_the_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('accounts.store'), [
            'code' => '6500',
            'name' => 'Fuel',
            'account_type' => AccountType::Expense->value,
            'normal_balance' => 'DEBIT',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $account = Account::where('code', '6500')->firstOrFail();
        $this->assertSame('Fuel', $account->name);

        $this->actingAs($user)->put(route('accounts.update', $account), [
            'code' => '6500',
            'name' => 'Fuel and Lubricants',
            'account_type' => AccountType::Expense->value,
            'normal_balance' => 'DEBIT',
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertSame('Fuel and Lubricants', $account->fresh()->name);
    }

    #[Test]
    public function a_duplicate_account_code_is_refused_by_the_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('accounts.store'), [
            'code' => '6400',
            'name' => 'Something else',
            'account_type' => AccountType::Expense->value,
            'normal_balance' => 'DEBIT',
        ])->assertSessionHasErrors('code');
    }

    #[Test]
    public function the_account_show_screen_reports_a_derived_balance(): void
    {
        $user = User::factory()->create();
        $this->postEntryThroughScreen($user);

        $this->actingAs($user)
            ->get(route('accounts.show', $this->electricity))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/accounts/Show')
                ->where('account.debit_total', '10000.000000')
                ->where('account.credit_total', '0.000000')
                ->where('account.balance', '10000.000000')
                ->where('account.has_history', true));
    }

    // ------------------------------------------------------------- journals

    #[Test]
    public function a_draft_journal_created_through_the_screen_affects_no_balance(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('journals.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $entry = JournalEntry::firstOrFail();

        $this->assertMatchesRegularExpression('/^JV-\d{8}-\d{6}$/', $entry->journal_number);
        $this->assertSame(JournalEntryStatus::Draft, $entry->status);
        $this->assertSame($this->period->id, $entry->fiscal_period_id);
        $this->assertCount(2, $entry->lines);

        $this->assertSame('0.000000', app(AccountingBalanceService::class)->debitTotal($this->electricity->id));
    }

    #[Test]
    public function the_create_screen_offers_only_accounts_that_accept_manual_posting(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('journals.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/journals/Create')
                // The control account is absent.
                ->has('accounts', 2)
                ->has('openPeriods', 1)
                ->where('currency', 'LKR'));
    }

    #[Test]
    public function the_screen_refuses_a_manual_line_against_a_control_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('journals.store'), $this->payload([
            'lines' => [
                ['account_id' => $this->control->id, 'debit' => '10000.000000', 'credit' => '0'],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => '10000.000000'],
            ],
        ]))->assertSessionHasNoErrors();

        // The draft saves — but posting is refused, which is where the rule lives.
        $entry = JournalEntry::firstOrFail();

        $this->actingAs($user)
            ->patch(route('journals.post', $entry))
            ->assertSessionHasErrors('lines.0.account_id');

        $this->assertSame(JournalEntryStatus::Draft, $entry->fresh()->status);
    }

    #[Test]
    public function the_screen_refuses_a_line_that_is_both_a_debit_and_a_credit(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('journals.store'), $this->payload([
            'lines' => [
                ['account_id' => $this->electricity->id, 'debit' => '10000.000000', 'credit' => '10000.000000'],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => '10000.000000'],
            ],
        ]))->assertSessionHasErrors('lines.0.debit');

        $this->assertSame(0, JournalEntry::count());
    }

    #[Test]
    public function the_screen_refuses_a_line_with_no_amount(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('journals.store'), $this->payload([
            'lines' => [
                ['account_id' => $this->electricity->id, 'debit' => '0', 'credit' => '0'],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => '10000.000000'],
            ],
        ]))->assertSessionHasErrors('lines.0.debit');
    }

    #[Test]
    public function the_screen_refuses_a_negative_amount(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('journals.store'), $this->payload([
            'lines' => [
                ['account_id' => $this->electricity->id, 'debit' => '-1.000000', 'credit' => '0'],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => '10000.000000'],
            ],
        ]))->assertSessionHasErrors('lines.0.debit');
    }

    #[Test]
    public function the_screen_refuses_fewer_than_two_lines(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('journals.store'), $this->payload([
            'lines' => [['account_id' => $this->electricity->id, 'debit' => '10000.000000', 'credit' => '0']],
        ]))->assertSessionHasErrors('lines');
    }

    #[Test]
    public function the_screen_refuses_a_date_with_no_open_period(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('journals.store'), $this->payload(['journal_date' => '2027-01-15']))
            ->assertSessionHasErrors('journal_date');

        $this->assertSame(0, JournalEntry::count());
    }

    #[Test]
    public function an_unbalanced_draft_saves_but_will_not_post(): void
    {
        $user = User::factory()->create();

        // A half-written entry is a legitimate draft.
        $this->actingAs($user)->post(route('journals.store'), $this->payload([
            'lines' => [
                ['account_id' => $this->electricity->id, 'debit' => '10000.000000', 'credit' => '0'],
                ['account_id' => $this->cash->id, 'debit' => '0', 'credit' => '9000.000000'],
            ],
        ]))->assertSessionHasNoErrors();

        $entry = JournalEntry::firstOrFail();
        $this->assertFalse($entry->isBalanced());

        $this->actingAs($user)
            ->patch(route('journals.post', $entry))
            ->assertSessionHasErrors('lines');

        $this->assertSame(JournalEntryStatus::Draft, $entry->fresh()->status);
    }

    #[Test]
    public function posting_through_the_screen_records_who_and_when(): void
    {
        $user = User::factory()->create();
        $entry = $this->postEntryThroughScreen($user);

        $this->assertSame(JournalEntryStatus::Posted, $entry->status);
        $this->assertSame($user->id, $entry->posted_by);
        $this->assertNotNull($entry->posted_at);

        $balances = app(AccountingBalanceService::class);
        $this->assertSame('10000.000000', $balances->debitTotal($this->electricity->id));
        $this->assertSame('10000.000000', $balances->creditTotal($this->cash->id));
    }

    #[Test]
    public function a_draft_can_be_edited_but_a_posted_entry_cannot(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('journals.store'), $this->payload());
        $entry = JournalEntry::firstOrFail();

        $this->actingAs($user)
            ->get(route('journals.edit', $entry))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/journals/Edit')
                ->has('entry.lines', 2));

        $this->actingAs($user)->put(route('journals.update', $entry), $this->payload([
            'description' => 'Electricity for September',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('Electricity for September', $entry->fresh()->description);

        $this->actingAs($user)->patch(route('journals.post', $entry));

        $this->actingAs($user)->get(route('journals.edit', $entry))->assertSessionHasErrors('status');
        $this->actingAs($user)->put(route('journals.update', $entry), $this->payload())->assertSessionHasErrors('status');
    }

    #[Test]
    public function the_show_screen_reports_the_entry_and_its_totals(): void
    {
        $user = User::factory()->create();
        $entry = $this->postEntryThroughScreen($user);

        $this->actingAs($user)
            ->get(route('journals.show', $entry))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/journals/Show')
                ->where('entry.status', 'POSTED')
                ->where('entry.debit_total', '10000.000000')
                ->where('entry.credit_total', '10000.000000')
                ->where('entry.difference', '0.000000')
                ->where('entry.is_balanced', true)
                ->where('entry.source', 'Manual')
                ->where('entry.can_reverse', true)
                ->where('entry.can_edit', false)
                ->has('entry.lines', 2));
    }

    #[Test]
    public function reversing_through_the_screen_creates_and_links_a_new_entry(): void
    {
        $user = User::factory()->create();
        $original = $this->postEntryThroughScreen($user);

        $this->actingAs($user)
            ->patch(route('journals.reverse', $original))
            ->assertSessionHasNoErrors();

        $reversal = JournalEntry::where('reversal_of_id', $original->id)->firstOrFail();

        $this->assertSame(JournalEntryStatus::Reversed, $original->fresh()->status);
        $this->assertSame($reversal->id, $original->fresh()->reversed_by_id);

        $balances = app(AccountingBalanceService::class);
        $this->assertSame('0.000000', $balances->netBalance($this->electricity->id));
        $this->assertSame('0.000000', $balances->netBalance($this->cash->id));
    }

    #[Test]
    public function a_draft_can_be_cancelled_through_the_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('journals.store'), $this->payload());
        $entry = JournalEntry::firstOrFail();

        $this->actingAs($user)
            ->patch(route('journals.cancel', $entry))
            ->assertSessionHasNoErrors();

        $this->assertSame(JournalEntryStatus::Cancelled, $entry->fresh()->status);
    }

    // ------------------------------------------------------------- reports

    #[Test]
    public function the_general_ledger_screen_shows_an_account_with_a_running_balance(): void
    {
        $user = User::factory()->create();
        $this->postEntryThroughScreen($user);

        $this->actingAs($user)
            ->get(route('general-ledger.index', ['account_id' => $this->electricity->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/general-ledger/Index')
                ->where('account.code', '6400')
                ->has('ledger.rows', 1)
                ->where('ledger.rows.0.debit', '10000.000000')
                ->where('ledger.rows.0.running_balance', '10000.000000')
                ->where('ledger.closing_balance', '10000.000000'));
    }

    #[Test]
    public function the_general_ledger_screen_asks_for_an_account_first(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('general-ledger.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('account', null)
                ->where('ledger', null));
    }

    #[Test]
    public function the_trial_balance_screen_balances(): void
    {
        $user = User::factory()->create();
        $this->postEntryThroughScreen($user);

        $this->actingAs($user)
            ->get(route('trial-balance.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/trial-balance/Index')
                ->has('report.rows', 2)
                ->where('report.debit_total', '10000.000000')
                ->where('report.credit_total', '10000.000000')
                ->where('report.balanced', true));
    }

    #[Test]
    public function the_trial_balance_screen_is_empty_and_balanced_before_anything_is_posted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('trial-balance.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('report.rows', 0)
                ->where('report.debit_total', '0.000000')
                ->where('report.credit_total', '0.000000')
                ->where('report.balanced', true));
    }

    // ------------------------------------------------------------- fiscal periods

    #[Test]
    public function a_fiscal_period_can_be_opened_and_closed_through_the_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('fiscal-periods.store'), [
            'name' => 'October 2026',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ])->assertSessionHasNoErrors();

        $october = FiscalPeriod::where('name', 'October 2026')->firstOrFail();
        $this->assertSame(FiscalPeriodStatus::Open, $october->status);

        $this->actingAs($user)
            ->patch(route('fiscal-periods.close', $october))
            ->assertSessionHasNoErrors();

        $this->assertSame(FiscalPeriodStatus::Closed, $october->fresh()->status);
        $this->assertSame($user->id, $october->fresh()->closed_by);
    }

    #[Test]
    public function overlapping_fiscal_periods_are_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('fiscal-periods.store'), [
            'name' => 'Mid September 2026',
            'start_date' => '2026-09-15',
            'end_date' => '2026-10-15',
        ])->assertSessionHasErrors('start_date');

        $this->assertSame(1, FiscalPeriod::count());
    }

    #[Test]
    public function a_period_holding_a_draft_cannot_be_closed_through_the_screen(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('journals.store'), $this->payload());

        $this->actingAs($user)
            ->patch(route('fiscal-periods.close', $this->period))
            ->assertSessionHasErrors('status');

        $this->assertSame(FiscalPeriodStatus::Open, $this->period->fresh()->status);
    }

    #[Test]
    public function the_fiscal_period_screen_reports_draft_counts(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('journals.store'), $this->payload());

        $this->actingAs($user)
            ->get(route('fiscal-periods.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/fiscal-periods/Index')
                ->has('periods', 1)
                ->where('periods.0.entries_count', 1)
                ->where('periods.0.draft_entries_count', 1)
                ->where('periods.0.can_close', false));
    }

    /**
     * Create and post one entry through the HTTP layer.
     */
    private function postEntryThroughScreen(User $user): JournalEntry
    {
        $this->actingAs($user)->post(route('journals.store'), $this->payload());
        $entry = JournalEntry::firstOrFail();
        $this->actingAs($user)->patch(route('journals.post', $entry));

        return $entry->fresh(['lines']);
    }
}
