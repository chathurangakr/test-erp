<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\CloseFinancialYear;
use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\ReverseFinancialYearClosing;
use App\Enums\FiscalPeriodStatus;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\FinancialYearClosing;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Accounting\BalanceSheetService;
use App\Services\Accounting\ProfitLossService;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Year-end closing (2026-10-25; the financial year ends 31 March): a finished year's profit moves
 * into retained earnings with one closing journal dated the year end. The P&L still shows the
 * year's real figures, and the balance sheet's current earnings start again.
 */
class YearEndClosingTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->accountant = User::factory()->admin()->create();

        foreach (['2026-09', '2026-10', '2027-03', '2027-04'] as $month) {
            $start = Carbon::parse($month.'-01');
            FiscalPeriod::factory()->create(['start_date' => $start->toDateString(), 'end_date' => $start->copy()->endOfMonth()->toDateString()]);
        }

        // The year 1 April 2026 – 31 March 2027: 100,000 of sales, 30,000 of electricity.
        $this->journal('2026-09-10', '1110', '4110', '100000');
        $this->journal('2026-10-05', '6400', '1110', '30000');
    }

    private function journal(string $date, string $debit, string $credit, string $amount): void
    {
        $entry = JournalEntry::factory()->create(['journal_date' => $date, 'fiscal_period_id' => FiscalPeriod::openFor(Carbon::parse($date))->id]);
        $entry->lines()->createMany([
            ['account_id' => Account::where('code', $debit)->value('id'), 'debit' => $amount, 'credit' => '0'],
            ['account_id' => Account::where('code', $credit)->value('id'), 'debit' => '0', 'credit' => $amount],
        ]);
        app(PostJournalEntry::class)->handle($entry, $this->accountant->id);
    }

    private function balance(string $code, ?string $asOf = null): string
    {
        return app(AccountingBalanceService::class)->balance(Account::where('code', $code)->firstOrFail(), $asOf === null ? null : Carbon::parse($asOf));
    }

    private function close(): FinancialYearClosing
    {
        return app(CloseFinancialYear::class)->handle('2027-03-31', $this->accountant->id);
    }

    #[Test]
    public function a_finished_year_closes_its_profit_into_retained_earnings(): void
    {
        $this->travelTo(Carbon::parse('2027-04-02 09:00', 'Asia/Colombo'));
        $closing = $this->close();

        $this->assertSame('70000.000000', (string) $closing->net_profit);
        $journal = $closing->journalEntry;
        $this->assertSame('2027-03-31', $journal->journal_date->toDateString());
        $this->assertSame(FinancialYearClosing::class, $journal->source_type);

        // Income and expense accounts are empty at the year end; retained earnings holds the profit.
        $this->assertSame('0.000000', $this->balance('4110', '2027-03-31'));
        $this->assertSame('0.000000', $this->balance('6400', '2027-03-31'));
        $this->assertSame('70000.000000', $this->balance('3200', '2027-03-31'));

        // The year's profit and loss still shows what was earned and spent.
        $this->assertSame('70000.000000', app(ProfitLossService::class)->netProfit(Carbon::parse('2026-04-01'), Carbon::parse('2027-03-31')));

        // The balance sheet: the profit sits in retained earnings, current earnings start again.
        $sheet = app(BalanceSheetService::class)->statement(Carbon::parse('2027-03-31'));
        $this->assertSame('0.000000', $sheet['current_earnings']);
        $this->assertTrue($sheet['balanced']);

        $this->journal('2027-04-01', '1110', '4110', '20000');
        $april = app(BalanceSheetService::class)->statement(Carbon::parse('2027-04-30'));
        $this->assertSame('20000.000000', $april['current_earnings']);
        $this->assertTrue($april['balanced']);
    }

    #[Test]
    public function a_year_closes_once_in_order_after_it_ends_while_its_last_month_is_open(): void
    {
        // Still running — on its last day too, even late in the Sri Lankan evening.
        $this->expectRefusal(fn () => $this->close(), 'can be closed from the next day');
        $this->travelTo(Carbon::parse('2027-03-31 23:30', 'Asia/Colombo'));
        $this->expectRefusal(fn () => $this->close(), 'can be closed from the next day');

        $this->travelTo(Carbon::parse('2027-04-02 09:00', 'Asia/Colombo'));
        $this->expectRefusal(fn () => app(CloseFinancialYear::class)->handle('2027-02-28'), 'A financial year ends on 2027-03-31');

        FiscalPeriod::query()->whereDate('start_date', '2027-03-01')->update(['status' => FiscalPeriodStatus::Closed]);
        $this->expectRefusal(fn () => $this->close(), 'Close the year before closing its last month');
        $this->assertSame(0, FinancialYearClosing::count(), 'A refused close leaves nothing behind.');

        FiscalPeriod::query()->whereDate('start_date', '2027-03-01')->update(['status' => FiscalPeriodStatus::Open]);
        $this->close();
        $this->expectRefusal(fn () => $this->close(), 'already closed');
        $this->expectRefusal(fn () => app(CloseFinancialYear::class)->handle('2026-03-31'), 'Years are closed in order');
    }

    #[Test]
    public function a_close_is_undone_on_the_same_year_end_day_and_only_from_its_own_page(): void
    {
        $this->travelTo(Carbon::parse('2027-04-02 09:00', 'Asia/Colombo'));
        $closing = $this->close();

        $this->actingAs($this->accountant)->patch(route('journals.reverse', $closing->journal_entry_id))->assertSessionHasErrors('status');

        app(ReverseFinancialYearClosing::class)->handle($closing, $this->accountant->id);

        $this->assertSame('0.000000', $this->balance('3200'));
        $this->assertSame('100000.000000', $this->balance('4110'));
        $this->assertSame('2027-03-31', $closing->fresh()->reversalJournalEntry->journal_date->toDateString());
        $this->assertSame('70000.000000', app(BalanceSheetService::class)->statement(Carbon::parse('2027-04-30'))['current_earnings']);

        // Closed again after the reversal.
        $this->assertSame('70000.000000', (string) $this->close()->net_profit);
    }

    #[Test]
    public function the_page_lists_each_year_and_closes_with_permission(): void
    {
        $this->travelTo(Carbon::parse('2027-04-02 09:00', 'Asia/Colombo'));
        $viewer = User::factory()->withPermissions(Permission::FinanceView)->create();

        $this->actingAs($viewer)->get(route('year-end.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/year-end/Index')
                ->where('endMonth', 'March')
                ->where('years.0.start', '2027-04-01')
                ->where('years.1.end', '2027-03-31')
                ->where('years.1.net_profit', '70000.000000')
                ->where('years.1.can_close', true));
        $this->actingAs($viewer)->post(route('year-end.store'), ['year_end_date' => '2027-03-31'])->assertForbidden();

        $this->actingAs($this->accountant)->post(route('year-end.store'), ['year_end_date' => '2027-03-31'])->assertSessionHasNoErrors();
        $this->assertSame(1, FinancialYearClosing::count());
    }

    private function expectRefusal(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail("Expected a refusal: {$message}");
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($message, implode(' ', $exception->validator->errors()->all()));
        }
    }
}
