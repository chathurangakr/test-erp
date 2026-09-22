<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\PostJournalEntry;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Services\Accounting\AccountingBalanceService;
use App\Support\DecimalMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Balances, the general ledger and the trial balance.
 *
 * Every figure here is derived from posted journal lines. Nothing is stored, so these tests
 * are really asking one question in several ways: does the arithmetic over the lines agree
 * with what the books say?
 */
class AccountingBalanceTest extends TestCase
{
    use RefreshDatabase;

    private FiscalPeriod $september;

    private FiscalPeriod $october;

    private Account $cash;

    private Account $sales;

    private Account $electricity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->september = FiscalPeriod::factory()->create([
            'name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);
        $this->october = FiscalPeriod::factory()->create([
            'name' => 'October 2026', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        ]);

        $this->cash = Account::factory()->ofType(AccountType::Asset)->create(['code' => '1110', 'name' => 'Cash on Hand']);
        $this->sales = Account::factory()->ofType(AccountType::Revenue)->create(['code' => '4110', 'name' => 'Coconut Sales']);
        $this->electricity = Account::factory()->ofType(AccountType::Expense)->create(['code' => '6400', 'name' => 'Electricity']);
    }

    private function balances(): AccountingBalanceService
    {
        return app(AccountingBalanceService::class);
    }

    /**
     * Post one balanced entry.
     *
     * @param  list<array{account: Account, debit?: string, credit?: string}>  $lines
     */
    private function postEntry(array $lines, string $date = '2026-09-15', ?FiscalPeriod $period = null): JournalEntry
    {
        $entry = JournalEntry::factory()->create([
            'fiscal_period_id' => ($period ?? $this->september)->id,
            'journal_date' => $date,
        ]);

        foreach ($lines as $line) {
            JournalEntryLine::factory()->create([
                'journal_entry_id' => $entry->id,
                'account_id' => $line['account']->id,
                'debit' => $line['debit'] ?? '0.000000',
                'credit' => $line['credit'] ?? '0.000000',
            ]);
        }

        return app(PostJournalEntry::class)->handle($entry->refresh());
    }

    /** A cash sale, then an electricity payment. */
    private function seedTwoEntries(): void
    {
        $this->postEntry([
            ['account' => $this->cash, 'debit' => '260000.000000'],
            ['account' => $this->sales, 'credit' => '260000.000000'],
        ], '2026-09-10');

        $this->postEntry([
            ['account' => $this->electricity, 'debit' => '10000.000000'],
            ['account' => $this->cash, 'credit' => '10000.000000'],
        ], '2026-09-20');
    }

    // ------------------------------------------------------------- totals

    #[Test]
    public function debit_and_credit_totals_come_from_posted_lines(): void
    {
        $this->seedTwoEntries();

        $this->assertSame('260000.000000', $this->balances()->debitTotal($this->cash->id));
        $this->assertSame('10000.000000', $this->balances()->creditTotal($this->cash->id));

        $this->assertSame('0.000000', $this->balances()->debitTotal($this->sales->id));
        $this->assertSame('260000.000000', $this->balances()->creditTotal($this->sales->id));
    }

    #[Test]
    public function the_net_balance_is_debits_minus_credits(): void
    {
        $this->seedTwoEntries();

        $this->assertSame('250000.000000', $this->balances()->netBalance($this->cash->id));
        $this->assertSame('-260000.000000', $this->balances()->netBalance($this->sales->id));
        $this->assertSame('10000.000000', $this->balances()->netBalance($this->electricity->id));
    }

    #[Test]
    public function a_debit_normal_account_reports_a_positive_balance_when_debits_lead(): void
    {
        $this->seedTwoEntries();

        // Cash is an asset: debits exceeding credits is a positive balance.
        $this->assertSame('250000.000000', $this->balances()->balance($this->cash));
        $this->assertSame('10000.000000', $this->balances()->balance($this->electricity));
    }

    #[Test]
    public function a_credit_normal_account_reports_a_positive_balance_when_credits_lead(): void
    {
        $this->seedTwoEntries();

        // Revenue is credit-normal: 260,000 credited reads as +260,000, not -260,000.
        $this->assertSame('260000.000000', $this->balances()->balance($this->sales));
        $this->assertSame('-260000.000000', $this->balances()->netBalance($this->sales->id));
    }

    #[Test]
    public function a_draft_or_cancelled_entry_contributes_nothing(): void
    {
        $this->seedTwoEntries();

        $draft = JournalEntry::factory()->create([
            'fiscal_period_id' => $this->september->id,
            'journal_date' => '2026-09-25',
        ]);
        JournalEntryLine::factory()->debit('999999.000000')->create([
            'journal_entry_id' => $draft->id,
            'account_id' => $this->cash->id,
        ]);

        $this->assertSame('250000.000000', $this->balances()->netBalance($this->cash->id));
    }

    #[Test]
    public function balances_by_account_type_aggregate_correctly(): void
    {
        $this->seedTwoEntries();

        $this->assertSame('250000.000000', $this->balances()->balanceForType(AccountType::Asset));
        $this->assertSame('-260000.000000', $this->balances()->balanceForType(AccountType::Revenue));
        $this->assertSame('10000.000000', $this->balances()->balanceForType(AccountType::Expense));
    }

    // ------------------------------------------------------------- as of / period

    #[Test]
    public function a_balance_as_of_a_date_excludes_later_entries(): void
    {
        $this->seedTwoEntries();

        // Only the 10 September sale has happened by the 15th.
        $this->assertSame('260000.000000', $this->balances()->netBalance($this->cash->id, Carbon::parse('2026-09-15')));
        $this->assertSame('0.000000', $this->balances()->netBalance($this->electricity->id, Carbon::parse('2026-09-15')));

        // By the 20th, both have.
        $this->assertSame('250000.000000', $this->balances()->netBalance($this->cash->id, Carbon::parse('2026-09-20')));

        // And before either.
        $this->assertSame('0.000000', $this->balances()->netBalance($this->cash->id, Carbon::parse('2026-09-01')));
    }

    #[Test]
    public function a_balance_for_a_fiscal_period_covers_only_that_period(): void
    {
        $this->seedTwoEntries();

        $this->postEntry([
            ['account' => $this->cash, 'debit' => '50000.000000'],
            ['account' => $this->sales, 'credit' => '50000.000000'],
        ], '2026-10-05', $this->october);

        $september = $this->balances()->balanceForPeriod($this->cash->id, $this->september);
        $october = $this->balances()->balanceForPeriod($this->cash->id, $this->october);

        $this->assertSame('260000.000000', $september['debit']);
        $this->assertSame('10000.000000', $september['credit']);
        $this->assertSame('250000.000000', $september['net']);

        $this->assertSame('50000.000000', $october['debit']);
        $this->assertSame('0.000000', $october['credit']);
        $this->assertSame('50000.000000', $october['net']);

        // Company-wide it is the sum of both.
        $this->assertSame('300000.000000', $this->balances()->netBalance($this->cash->id));
    }

    // ------------------------------------------------------------- general ledger

    #[Test]
    public function the_general_ledger_returns_the_lines_for_one_account_with_a_running_balance(): void
    {
        $this->seedTwoEntries();

        $ledger = $this->balances()->generalLedger($this->cash);

        $this->assertCount(2, $ledger['rows']);
        $this->assertSame('0.000000', $ledger['opening_balance']);

        // Oldest first, with the balance running down the column.
        $this->assertSame('2026-09-10', $ledger['rows'][0]['journal_date']);
        $this->assertSame('260000.000000', $ledger['rows'][0]['debit']);
        $this->assertSame('260000.000000', $ledger['rows'][0]['running_balance']);

        $this->assertSame('2026-09-20', $ledger['rows'][1]['journal_date']);
        $this->assertSame('10000.000000', $ledger['rows'][1]['credit']);
        $this->assertSame('250000.000000', $ledger['rows'][1]['running_balance']);

        $this->assertSame('260000.000000', $ledger['debit_total']);
        $this->assertSame('10000.000000', $ledger['credit_total']);
        $this->assertSame('250000.000000', $ledger['closing_balance']);
    }

    #[Test]
    public function the_general_ledger_folds_earlier_activity_into_an_opening_balance(): void
    {
        $this->seedTwoEntries();

        // A window that starts after the first entry.
        $ledger = $this->balances()->generalLedger($this->cash, Carbon::parse('2026-09-15'));

        $this->assertSame('260000.000000', $ledger['opening_balance']);
        $this->assertCount(1, $ledger['rows']);
        $this->assertSame('250000.000000', $ledger['rows'][0]['running_balance']);
        $this->assertSame('250000.000000', $ledger['closing_balance']);
    }

    #[Test]
    public function the_general_ledger_respects_an_end_date(): void
    {
        $this->seedTwoEntries();

        $ledger = $this->balances()->generalLedger($this->cash, null, Carbon::parse('2026-09-15'));

        $this->assertCount(1, $ledger['rows']);
        $this->assertSame('260000.000000', $ledger['closing_balance']);
    }

    #[Test]
    public function the_general_ledger_labels_a_manual_entry_as_manual(): void
    {
        $this->seedTwoEntries();

        $ledger = $this->balances()->generalLedger($this->cash);

        $this->assertSame('Manual', $ledger['rows'][0]['source']);
    }

    #[Test]
    public function the_general_ledger_of_an_untouched_account_is_empty_not_broken(): void
    {
        $untouched = Account::factory()->ofType(AccountType::Asset)->create(['code' => '1120']);

        $ledger = $this->balances()->generalLedger($untouched);

        $this->assertSame([], $ledger['rows']);
        $this->assertSame('0.000000', $ledger['closing_balance']);
    }

    // ------------------------------------------------------------- trial balance

    #[Test]
    public function the_trial_balance_lists_every_account_with_a_balance(): void
    {
        $this->seedTwoEntries();

        $report = $this->balances()->trialBalance();
        $codes = array_column($report['rows'], 'code');

        $this->assertSame(['1110', '4110', '6400'], $codes);

        $rows = collect($report['rows'])->keyBy('code');

        $this->assertSame('250000.000000', $rows['1110']['debit']);
        $this->assertSame('0.000000', $rows['1110']['credit']);

        $this->assertSame('260000.000000', $rows['4110']['credit']);
        $this->assertSame('0.000000', $rows['4110']['debit']);

        $this->assertSame('10000.000000', $rows['6400']['debit']);
    }

    #[Test]
    public function the_trial_balance_totals_match_exactly(): void
    {
        $this->seedTwoEntries();

        $report = $this->balances()->trialBalance();

        // 250,000 + 10,000 debit against 260,000 credit.
        $this->assertSame('260000.000000', $report['debit_total']);
        $this->assertSame('260000.000000', $report['credit_total']);
        $this->assertTrue($report['balanced']);
        $this->assertSame(
            '0.000000',
            DecimalMath::cost(DecimalMath::sub($report['debit_total'], $report['credit_total'])),
        );
    }

    #[Test]
    public function the_trial_balance_is_empty_and_balanced_before_anything_is_posted(): void
    {
        $report = $this->balances()->trialBalance();

        $this->assertSame([], $report['rows']);
        $this->assertSame('0.000000', $report['debit_total']);
        $this->assertSame('0.000000', $report['credit_total']);
        $this->assertTrue($report['balanced']);
    }

    #[Test]
    public function the_trial_balance_omits_an_account_whose_postings_cancel_out(): void
    {
        $this->postEntry([
            ['account' => $this->cash, 'debit' => '1000.000000'],
            ['account' => $this->sales, 'credit' => '1000.000000'],
        ]);
        $this->postEntry([
            ['account' => $this->sales, 'debit' => '1000.000000'],
            ['account' => $this->cash, 'credit' => '1000.000000'],
        ]);

        $report = $this->balances()->trialBalance();

        $this->assertSame([], $report['rows']);
        $this->assertTrue($report['balanced']);
    }

    #[Test]
    public function the_trial_balance_can_be_taken_as_of_a_date(): void
    {
        $this->seedTwoEntries();

        $report = $this->balances()->trialBalance(Carbon::parse('2026-09-15'));

        $this->assertSame(['1110', '4110'], array_column($report['rows'], 'code'));
        $this->assertSame('260000.000000', $report['debit_total']);
        $this->assertSame('260000.000000', $report['credit_total']);
        $this->assertTrue($report['balanced']);
    }

    #[Test]
    public function the_trial_balance_can_be_taken_for_one_fiscal_period(): void
    {
        $this->seedTwoEntries();

        $this->postEntry([
            ['account' => $this->cash, 'debit' => '50000.000000'],
            ['account' => $this->sales, 'credit' => '50000.000000'],
        ], '2026-10-05', $this->october);

        $october = $this->balances()->trialBalance(null, $this->october);

        $this->assertSame('50000.000000', $october['debit_total']);
        $this->assertSame('50000.000000', $october['credit_total']);
        $this->assertTrue($october['balanced']);
    }

    /**
     * The point of the report: whatever is posted, the two sides agree.
     */
    #[Test]
    public function the_trial_balance_stays_balanced_across_many_awkward_entries(): void
    {
        foreach ([
            ['0.333333', '0.333333'],
            ['16000.000000', '16000.000000'],
            ['87125.000000', '87125.000000'],
            ['0.000001', '0.000001'],
        ] as [$debit, $credit]) {
            $this->postEntry([
                ['account' => $this->electricity, 'debit' => $debit],
                ['account' => $this->cash, 'credit' => $credit],
            ]);
        }

        $report = $this->balances()->trialBalance();

        $this->assertSame('103125.333334', $report['debit_total']);
        $this->assertSame('103125.333334', $report['credit_total']);
        $this->assertTrue($report['balanced']);
    }
}
