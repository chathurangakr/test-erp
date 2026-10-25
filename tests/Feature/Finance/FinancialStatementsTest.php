<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\ReverseJournalEntry;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\ProfitLossService;
use App\Support\DecimalMath;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The profit and loss statement and the balance sheet.
 *
 * Both derive entirely from posted journal lines. The identity that matters:
 *
 *   Assets  =  Liabilities  +  Equity  +  Current Earnings
 *
 * It is not a coincidence to be checked hopefully — it follows from every journal balancing,
 * so a failure here means the trial balance itself is broken.
 */
class FinancialStatementsTest extends TestCase
{
    use RefreshDatabase;

    /** Marks fixture journals as system-generated, as the real operational ones are. */
    private const SOURCE = 'ReportingFixture';

    private FiscalPeriod $september;

    private FiscalPeriod $october;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);

        $this->september = FiscalPeriod::factory()->create([
            'name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);
        $this->october = FiscalPeriod::factory()->create([
            'name' => 'October 2026', 'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        ]);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    private function profitLoss(): ProfitLossService
    {
        return app(ProfitLossService::class);
    }

    private function balanceSheet(): BalanceSheetService
    {
        return app(BalanceSheetService::class);
    }

    /**
     * Post a balanced journal from [account code, debit, credit] triples.
     *
     * Marked system-generated, because these fixtures stand in for the journals purchasing,
     * sales and inventory post — and control accounts such as Trade Receivables and the
     * inventory accounts rightly refuse a journal written by hand.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $lines
     */
    private function journal(array $lines, string $date = '2026-09-10'): JournalEntry
    {
        $period = FiscalPeriod::openFor(Carbon::parse($date));

        $entry = JournalEntry::create([
            'journal_number' => 'JV-'.substr(md5($date.serialize($lines).uniqid()), 0, 12),
            'journal_date' => $date,
            'fiscal_period_id' => $period?->id,
            'source_type' => self::SOURCE,
            'description' => 'Test entry',
            'status' => JournalEntryStatus::Draft,
        ]);

        foreach ($lines as [$code, $debit, $credit]) {
            $entry->lines()->create([
                'account_id' => $this->account($code)->id,
                'debit' => $debit,
                'credit' => $credit,
            ]);
        }

        return app(PostJournalEntry::class)->handle($entry->refresh());
    }

    #[Test]
    public function the_window_compared_with_keeps_whole_months_whole(): void
    {
        $window = fn (string $from, string $to, string $mode) => array_map(
            fn ($date) => $date->toDateString(),
            ProfitLossService::priorWindow(Carbon::parse($from), Carbon::parse($to), $mode),
        );

        $this->assertSame(['2026-02-01', '2026-02-28'], $window('2026-03-01', '2026-03-31', 'previous'));
        $this->assertSame(['2026-04-01', '2026-06-30'], $window('2026-07-01', '2026-09-30', 'previous'));
        $this->assertSame(['2026-09-01', '2026-09-10'], $window('2026-09-11', '2026-09-20', 'previous'));
        $this->assertSame(['2025-09-01', '2025-09-30'], $window('2026-09-01', '2026-09-30', 'last_year'));
        $this->assertSame(['2027-02-01', '2027-02-28'], $window('2028-02-01', '2028-02-29', 'last_year'));
    }

    #[Test]
    public function a_statement_is_compared_account_by_account_with_an_earlier_window(): void
    {
        $this->journal([['1210', '200000.000000', '0.000000'], ['4110', '0.000000', '200000.000000']], '2026-09-10');
        $this->journal([['6400', '10000.000000', '0.000000'], ['1120', '0.000000', '10000.000000']], '2026-09-12');
        $this->journal([['1210', '250000.000000', '0.000000'], ['4110', '0.000000', '250000.000000']], '2026-10-10');
        $this->journal([['6400', '12000.000000', '0.000000'], ['1120', '0.000000', '12000.000000']], '2026-10-12');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports.profit-loss', ['fiscal_period_id' => $this->october->id, 'compare' => 'previous']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('comparison.prior_from', '2026-09-01')
                ->where('comparison.prior_to', '2026-09-30')
                ->where('comparison.lines.0', ['key' => 'revenue', 'label' => 'Revenue', 'current' => '250000.000000', 'prior' => '200000.000000', 'change' => '50000.000000', 'change_percent' => '25.0'])
                ->where('comparison.lines.4.change', '48000.000000')
                ->where('comparison.lines.4.current', '238000.000000'));

        foreach (['pdf', 'xlsx'] as $format) {
            $this->get(route('reports.profit-loss.export', ['fiscal_period_id' => $this->october->id, 'compare' => 'last_year', 'format' => $format]))->assertOk();
        }

        // An account that moved only in the earlier window is still listed, at zero now.
        $comparison = $this->profitLoss()->compare(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $expenses = collect($comparison['sections'])->firstWhere('key', 'operating_expenses');
        $row = collect($expenses['rows'])->firstWhere('code', '6400');
        $this->assertSame(['0.000000', '12000.000000', '-12000.000000', '-100.0'], [$row['current'], $row['prior'], $row['change'], $row['change_percent']]);

        // Against a window with nothing in it there is no percentage — the figure is new.
        $fromNothing = $this->profitLoss()->compare(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
        $this->assertNull(collect($fromNothing['lines'])->firstWhere('key', 'revenue')['change_percent']);
    }

    /** Find a row anywhere in a statement tree by account code. */
    private function findRow(array $rows, string $code): ?array
    {
        foreach ($rows as $row) {
            if ($row['code'] === $code) {
                return $row;
            }

            $found = $this->findRow($row['children'], $code);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Revenue 340,000 · COGS 255,000 · Expenses 15,000 — the worked example from the spec.
     */
    private function tradingActivity(): void
    {
        $this->journal([['1210', '260000.000000', '0.000000'], ['4110', '0.000000', '260000.000000']]);
        $this->journal([['1210', '80000.000000', '0.000000'], ['4140', '0.000000', '80000.000000']]);
        $this->journal([['5110', '205000.000000', '0.000000'], ['1310', '0.000000', '205000.000000']]);
        $this->journal([['5140', '50000.000000', '0.000000'], ['1350', '0.000000', '50000.000000']]);
        $this->journal([['6400', '10000.000000', '0.000000'], ['1120', '0.000000', '10000.000000']]);
        $this->journal([['6500', '5000.000000', '0.000000'], ['1120', '0.000000', '5000.000000']]);
    }

    // ------------------------------------------------------------ profit and loss

    #[Test]
    public function an_empty_profit_and_loss_reports_zero_everywhere(): void
    {
        $statement = $this->profitLoss()->statement();

        $this->assertSame('0.000000', $statement['revenue']['total']);
        $this->assertSame('0.000000', $statement['cost_of_sales']['total']);
        $this->assertSame('0.000000', $statement['gross_profit']);
        $this->assertSame('0.000000', $statement['operating_expenses']['total']);
        $this->assertSame('0.000000', $statement['net_profit']);
        // No division by zero.
        $this->assertSame('0.00', $statement['gross_margin_percent']);
        $this->assertSame([], $statement['revenue']['rows']);
    }

    #[Test]
    public function revenue_cost_of_sales_and_expenses_are_classified_by_account_type(): void
    {
        $this->tradingActivity();
        $statement = $this->profitLoss()->statement();

        $this->assertSame('340000.000000', $statement['revenue']['total']);
        $this->assertSame('255000.000000', $statement['cost_of_sales']['total']);
        $this->assertSame('15000.000000', $statement['operating_expenses']['total']);

        // Each account landed in its own section and nowhere else.
        $this->assertNotNull($this->findRow($statement['revenue']['rows'], '4110'));
        $this->assertNull($this->findRow($statement['revenue']['rows'], '5110'));
        $this->assertNotNull($this->findRow($statement['cost_of_sales']['rows'], '5110'));
        $this->assertNull($this->findRow($statement['cost_of_sales']['rows'], '6400'));
        $this->assertNotNull($this->findRow($statement['operating_expenses']['rows'], '6400'));
    }

    #[Test]
    public function gross_profit_and_net_profit_follow_the_statement_arithmetic(): void
    {
        $this->tradingActivity();
        $statement = $this->profitLoss()->statement();

        // 340,000 − 255,000
        $this->assertSame('85000.000000', $statement['gross_profit']);
        // 85,000 − 15,000
        $this->assertSame('70000.000000', $statement['net_profit']);
        $this->assertSame('25.00', $statement['gross_margin_percent']);
    }

    #[Test]
    public function revenue_prints_positive_even_though_it_is_a_credit_balance(): void
    {
        $this->tradingActivity();

        // The ledger still holds a negative net; only the statement flips it.
        $this->assertSame('-260000.000000', app(AccountingBalanceService::class)->netBalance($this->account('4110')->id));

        $row = $this->findRow($this->profitLoss()->statement()['revenue']['rows'], '4110');
        $this->assertSame('260000.000000', $row['amount']);
    }

    #[Test]
    public function an_abnormal_balance_prints_negative(): void
    {
        // A revenue account in debit — a genuine anomaly the reader must be able to see.
        $this->journal([['4110', '5000.000000', '0.000000'], ['1120', '0.000000', '5000.000000']]);

        $row = $this->findRow($this->profitLoss()->statement()['revenue']['rows'], '4110');

        $this->assertSame('-5000.000000', $row['amount']);
        $this->assertSame('-5000.000000', $this->profitLoss()->statement()['revenue']['total']);
    }

    #[Test]
    public function a_reversed_journal_nets_to_nothing_in_the_profit_and_loss(): void
    {
        $sale = $this->journal([['1210', '260000.000000', '0.000000'], ['4110', '0.000000', '260000.000000']]);

        $this->assertSame('260000.000000', $this->profitLoss()->statement()['revenue']['total']);

        app(ReverseJournalEntry::class)->handle($sale, null, ['journal_date' => '2026-09-20']);

        // The original stays visible and the reversal cancels it — net zero, nothing deleted.
        $statement = $this->profitLoss()->statement();
        $this->assertSame('0.000000', $statement['revenue']['total']);
        $this->assertSame('0.000000', $statement['net_profit']);
        $this->assertSame(2, JournalEntry::count());
    }

    #[Test]
    public function the_profit_and_loss_honours_a_date_window(): void
    {
        $this->journal([['1210', '100000.000000', '0.000000'], ['4110', '0.000000', '100000.000000']], '2026-09-05');
        $this->journal([['1210', '250000.000000', '0.000000'], ['4110', '0.000000', '250000.000000']], '2026-10-05');

        $september = $this->profitLoss()->statement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $october = $this->profitLoss()->statement(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $both = $this->profitLoss()->statement();

        $this->assertSame('100000.000000', $september['revenue']['total']);
        $this->assertSame('250000.000000', $october['revenue']['total']);
        $this->assertSame('350000.000000', $both['revenue']['total']);
    }

    #[Test]
    public function the_profit_and_loss_honours_a_fiscal_period(): void
    {
        $this->journal([['1210', '100000.000000', '0.000000'], ['4110', '0.000000', '100000.000000']], '2026-09-05');
        $this->journal([['1210', '250000.000000', '0.000000'], ['4110', '0.000000', '250000.000000']], '2026-10-05');

        $statement = $this->profitLoss()->statement(period: $this->september);

        $this->assertSame('100000.000000', $statement['revenue']['total']);
        $this->assertSame('September 2026', $statement['period']);
        $this->assertSame('2026-09-01', $statement['from']);
        $this->assertSame('2026-09-30', $statement['to']);
    }

    #[Test]
    public function accounts_are_grouped_under_their_parent_without_double_counting(): void
    {
        $this->tradingActivity();
        $statement = $this->profitLoss()->statement();

        // 4000 Revenue → 4100 Sales Revenue → 4110 Coconut Sales / 4140 Copra Sales
        $root = $statement['revenue']['rows'][0];
        $this->assertSame('4000', $root['code']);
        $this->assertTrue($root['is_group']);

        // The header itself holds nothing; its total is entirely its children.
        $this->assertSame('0.000000', $root['own_amount']);
        $this->assertSame('340000.000000', $root['amount']);

        $salesRevenue = $root['children'][0];
        $this->assertSame('4100', $salesRevenue['code']);
        $this->assertSame('340000.000000', $salesRevenue['amount']);

        $leaves = array_column($salesRevenue['children'], 'amount', 'code');
        $this->assertSame('260000.000000', $leaves['4110']);
        $this->assertSame('80000.000000', $leaves['4140']);

        // Exactly one root, so the section total counts the tree once.
        $this->assertCount(1, $statement['revenue']['rows']);
        $this->assertSame($root['amount'], $statement['revenue']['total']);
    }

    #[Test]
    public function a_postable_parent_adds_its_own_balance_alongside_its_children(): void
    {
        // 4100 normally refuses manual posting; allow it, so the aggregation is exercised
        // in the one shape that could double-count.
        $this->account('4100')->forceFill(['allow_manual_posting' => true])->save();

        $this->journal([['1210', '30000.000000', '0.000000'], ['4100', '0.000000', '30000.000000']]);
        $this->journal([['1210', '70000.000000', '0.000000'], ['4110', '0.000000', '70000.000000']]);

        $statement = $this->profitLoss()->statement();
        $salesRevenue = $this->findRow($statement['revenue']['rows'], '4100');

        $this->assertSame('30000.000000', $salesRevenue['own_amount']);
        // Own 30,000 plus child 70,000 — counted once each.
        $this->assertSame('100000.000000', $salesRevenue['amount']);
        $this->assertSame('100000.000000', $statement['revenue']['total']);
    }

    // -------------------------------------------------------------- balance sheet

    #[Test]
    public function an_empty_balance_sheet_reports_zero_and_still_balances(): void
    {
        $sheet = $this->balanceSheet()->statement();

        $this->assertSame('0.000000', $sheet['assets']['total']);
        $this->assertSame('0.000000', $sheet['liabilities']['total']);
        $this->assertSame('0.000000', $sheet['equity']['total']);
        $this->assertSame('0.000000', $sheet['current_earnings']);
        $this->assertSame('0.000000', $sheet['difference']);
        $this->assertTrue($sheet['balanced']);
    }

    #[Test]
    public function assets_liabilities_and_equity_are_reported_at_their_natural_sign(): void
    {
        // Capital in, goods bought on credit.
        $this->journal([['1120', '2000000.000000', '0.000000'], ['3100', '0.000000', '2000000.000000']]);
        $this->journal([['1310', '500000.000000', '0.000000'], ['2110', '0.000000', '500000.000000']]);

        $sheet = $this->balanceSheet()->statement();

        $this->assertSame('2500000.000000', $sheet['assets']['total']);
        $this->assertSame('500000.000000', $sheet['liabilities']['total']);
        $this->assertSame('2000000.000000', $sheet['equity']['total']);
        $this->assertTrue($sheet['balanced']);
    }

    #[Test]
    public function current_earnings_carries_the_unclosed_profit_and_the_sheet_balances(): void
    {
        $this->journal([['1120', '2000000.000000', '0.000000'], ['3100', '0.000000', '2000000.000000']]);
        $this->tradingActivity();

        $sheet = $this->balanceSheet()->statement();

        // The same 70,000 the profit and loss reports.
        $this->assertSame('70000.000000', $sheet['current_earnings']);
        $this->assertSame($this->profitLoss()->statement()['net_profit'], $sheet['current_earnings']);

        $this->assertSame('2000000.000000', $sheet['equity']['total']);
        $this->assertSame('2070000.000000', $sheet['total_equity']);
        $this->assertSame($sheet['assets']['total'], $sheet['liabilities_and_equity']);
        $this->assertSame('0.000000', $sheet['difference']);
        $this->assertTrue($sheet['balanced']);
    }

    #[Test]
    public function retained_earnings_is_never_written_to_by_the_report(): void
    {
        $this->journal([['1120', '2000000.000000', '0.000000'], ['3100', '0.000000', '2000000.000000']]);
        $this->tradingActivity();

        $retained = $this->account('3200');
        $before = app(AccountingBalanceService::class)->netBalance($retained->id);

        $sheet = $this->balanceSheet()->statement();

        // Profit of 70,000 exists, and retained earnings is untouched — no closing entry has
        // been posted, and this report will not invent one.
        $this->assertSame('70000.000000', $sheet['current_earnings']);
        $this->assertSame('0.000000', $before);
        $this->assertSame('0.000000', app(AccountingBalanceService::class)->netBalance($retained->id));
        $this->assertNull($this->findRow($sheet['equity']['rows'], '3200'));
    }

    #[Test]
    public function a_retained_earnings_balance_that_was_posted_shows_as_itself(): void
    {
        // An accountant posting to 3200 by hand is ordinary; the report just reads it.
        $this->journal([['1120', '400000.000000', '0.000000'], ['3200', '0.000000', '400000.000000']]);

        $sheet = $this->balanceSheet()->statement();
        $retained = $this->findRow($sheet['equity']['rows'], '3200');

        $this->assertSame('400000.000000', $retained['amount']);
        $this->assertSame('400000.000000', $sheet['equity']['total']);
        $this->assertTrue($sheet['balanced']);
    }

    #[Test]
    public function profit_and_loss_accounts_never_appear_as_balance_sheet_rows(): void
    {
        $this->tradingActivity();
        $sheet = $this->balanceSheet()->statement();

        foreach (['4110', '5110', '6400'] as $code) {
            foreach (['assets', 'liabilities', 'equity'] as $section) {
                $this->assertNull(
                    $this->findRow($sheet[$section]['rows'], $code),
                    "Account {$code} must not appear under {$section}.",
                );
            }
        }

        // Their effect reaches the sheet only through current earnings.
        $this->assertSame('70000.000000', $sheet['current_earnings']);
    }

    #[Test]
    public function the_balance_sheet_honours_its_as_of_date(): void
    {
        $this->journal([['1120', '1000000.000000', '0.000000'], ['3100', '0.000000', '1000000.000000']], '2026-09-05');
        $this->journal([['1120', '500000.000000', '0.000000'], ['3100', '0.000000', '500000.000000']], '2026-10-05');

        $september = $this->balanceSheet()->statement(Carbon::parse('2026-09-30'));
        $october = $this->balanceSheet()->statement(Carbon::parse('2026-10-31'));

        $this->assertSame('1000000.000000', $september['assets']['total']);
        $this->assertSame('1500000.000000', $october['assets']['total']);
        $this->assertTrue($september['balanced']);
        $this->assertTrue($october['balanced']);
    }

    #[Test]
    public function the_balance_sheet_groups_assets_under_the_chart_hierarchy(): void
    {
        $this->journal([['1110', '50000.000000', '0.000000'], ['3100', '0.000000', '50000.000000']]);
        $this->journal([['1120', '450000.000000', '0.000000'], ['3100', '0.000000', '450000.000000']]);
        $this->journal([['1310', '200000.000000', '0.000000'], ['2110', '0.000000', '200000.000000']]);

        $sheet = $this->balanceSheet()->statement();

        $cashGroup = $this->findRow($sheet['assets']['rows'], '1100');
        $this->assertSame('Cash and Cash Equivalents', $cashGroup['name']);
        $this->assertSame('500000.000000', $cashGroup['amount']);
        $this->assertSame(['1110', '1120'], array_column($cashGroup['children'], 'code'));

        $inventoryGroup = $this->findRow($sheet['assets']['rows'], '1300');
        $this->assertSame('200000.000000', $inventoryGroup['amount']);

        // 50,000 + 450,000 + 200,000, counted once each.
        $this->assertSame('700000.000000', $sheet['assets']['total']);
    }

    #[Test]
    public function the_balance_sheet_equation_holds_through_a_reversal(): void
    {
        $this->journal([['1120', '2000000.000000', '0.000000'], ['3100', '0.000000', '2000000.000000']]);
        $sale = $this->journal([['1210', '260000.000000', '0.000000'], ['4110', '0.000000', '260000.000000']]);

        $this->assertTrue($this->balanceSheet()->statement()['balanced']);

        app(ReverseJournalEntry::class)->handle($sale, null, ['journal_date' => '2026-09-25']);

        $sheet = $this->balanceSheet()->statement();
        $this->assertSame('0.000000', $sheet['current_earnings']);
        $this->assertSame('2000000.000000', $sheet['assets']['total']);
        $this->assertTrue($sheet['balanced']);
    }

    #[Test]
    public function the_statements_agree_with_the_trial_balance(): void
    {
        $this->journal([['1120', '2000000.000000', '0.000000'], ['3100', '0.000000', '2000000.000000']]);
        $this->tradingActivity();

        $trial = app(AccountingBalanceService::class)->trialBalance();
        $this->assertTrue($trial['balanced']);

        // The balance sheet equation is the trial balance, rearranged — so if one holds the
        // other must, and this asserts they were derived from the same rows.
        $sheet = $this->balanceSheet()->statement();
        $this->assertTrue($sheet['balanced']);
        $this->assertSame(
            DecimalMath::cost(DecimalMath::sub($sheet['assets']['total'], $sheet['liabilities_and_equity'])),
            '0.000000',
        );
    }
}
