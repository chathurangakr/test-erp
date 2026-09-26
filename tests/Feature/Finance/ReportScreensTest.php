<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\PostJournalEntry;
use App\Enums\JournalEntryStatus;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The financial statement and management report screens.
 *
 * The figures themselves are proven in FinancialStatementsTest and ManagementReportsTest;
 * these tests prove each screen is reachable only when signed in, renders the page it claims
 * to, passes its filters through, and that the whole reporting area is read-only.
 */
class ReportScreensTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT_ROUTES = [
        'finance-dashboard.index' => 'finance/Dashboard',
        'reports.profit-loss' => 'finance/reports/ProfitLoss',
        'reports.balance-sheet' => 'finance/reports/BalanceSheet',
        'reports.inventory-valuation' => 'finance/reports/InventoryValuation',
        'reports.inventory-gl-reconciliation' => 'finance/reports/InventoryGlReconciliation',
        'reports.cash-bank-position' => 'finance/reports/CashBankPosition',
        'reports.product-profitability' => 'finance/reports/ProductProfitability',
    ];

    private FiscalPeriod $september;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);

        $this->september = FiscalPeriod::factory()->create([
            'name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);
    }

    /**
     * Pay a 10,000 electricity bill from the bank on the given date.
     */
    private function electricityPaid(string $date = '2026-09-10'): void
    {
        $entry = JournalEntry::create([
            'journal_number' => 'JV-'.substr(md5($date.uniqid()), 0, 12),
            'journal_date' => $date,
            'fiscal_period_id' => FiscalPeriod::openFor(now()->parse($date))?->id,
            'source_type' => 'ReportingFixture',
            'description' => 'Electricity',
            'status' => JournalEntryStatus::Draft,
        ]);

        $entry->lines()->create(['account_id' => Account::where('code', '6400')->value('id'), 'debit' => '10000.000000', 'credit' => '0']);
        $entry->lines()->create(['account_id' => Account::where('code', '1120')->value('id'), 'debit' => '0', 'credit' => '10000.000000']);

        app(PostJournalEntry::class)->handle($entry->refresh());
    }

    #[Test]
    public function a_guest_cannot_open_any_report(): void
    {
        foreach (array_keys(self::REPORT_ROUTES) as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }

        $this->assertGuest();
    }

    #[Test]
    public function every_report_screen_renders_its_page_with_empty_books(): void
    {
        $user = User::factory()->admin()->create();

        foreach (self::REPORT_ROUTES as $name => $component) {
            $this->actingAs($user)
                ->get(route($name))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component($component)
                    ->where('currency', config('accounting.base_currency')));
        }
    }

    #[Test]
    public function the_reporting_area_is_read_only(): void
    {
        $writes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'finance/reports')
                || str_starts_with($route->uri(), 'finance/dashboard'))
            ->reject(fn ($route) => $route->methods() === ['GET', 'HEAD']);

        $this->assertCount(0, $writes, 'Reports must never write: '.$writes->map->uri()->implode(', '));
    }

    #[Test]
    public function the_profit_and_loss_screen_honours_a_fiscal_period(): void
    {
        $this->electricityPaid('2026-09-10');

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports.profit-loss', ['fiscal_period_id' => $this->september->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/reports/ProfitLoss')
                ->where('filters.fiscal_period_id', $this->september->id)
                ->where('statement.period', 'September 2026')
                ->where('statement.operating_expenses.total', '10000.000000')
                ->where('statement.net_profit', '-10000.000000'));
    }

    #[Test]
    public function the_balance_sheet_screen_shows_retained_earnings_beside_current_earnings(): void
    {
        $this->electricityPaid();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('reports.balance-sheet', ['as_of' => '2026-09-30']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/reports/BalanceSheet')
                ->where('filters.as_of', '2026-09-30')
                ->where('statement.current_earnings', '-10000.000000')
                ->where('statement.balanced', true)
                ->where('retainedEarnings.code', '3200')
                ->where('retainedEarnings.balance', '0.000000'));
    }

    #[Test]
    public function the_dashboard_uses_a_chosen_period_and_the_cash_position_follows_its_date(): void
    {
        $this->electricityPaid('2026-09-10');
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->get(route('finance-dashboard.index', ['fiscal_period_id' => $this->september->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/Dashboard')
                ->where('summary.period.name', 'September 2026')
                ->where('summary.performance.operating_expenses', '10000.000000')
                ->where('summary.position.bank_total', '-10000.000000'));

        $this->actingAs($user)
            ->get(route('reports.cash-bank-position', ['as_of' => '2026-09-05']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/reports/CashBankPosition')
                ->where('filters.as_of', '2026-09-05')
                ->where('summary.combined_total', '0.000000'));
    }
}
