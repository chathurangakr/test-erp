<?php

namespace Tests\Feature\Finance;

use App\Actions\Assets\RecordFixedAsset;
use App\Actions\Loans\RecordLoan;
use App\Models\CashBankAccount;
use App\Models\FiscalPeriod;
use App\Models\User;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Things the factory already had before the books began (2026-10-25): an asset already owned and a
 * loan already running keep their real dates but are booked on the day they are entered when no
 * open period covers those dates — and the finance dashboard shows loans still owed and fixed
 * assets.
 */
class OpeningEntriesAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        // The books start in September 2026; the suite's today is inside it.
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function an_asset_and_a_loan_from_before_the_books_are_booked_on_the_day_entered(): void
    {
        $tractor = app(RecordFixedAsset::class)->handle([
            'name' => 'Tractor', 'category' => 'VEHICLE', 'purchase_date' => '2025-03-15',
            'purchase_price' => '2500000', 'funding' => 'EXISTING',
        ], $this->admin->id);
        $loan = app(RecordLoan::class)->handle([
            'lender' => 'Samurdhi bank', 'start_date' => '2025-03-15', 'funding' => 'EXISTING', 'amount' => '438000',
        ], $this->admin->id);

        $today = now('Asia/Colombo')->toDateString();
        $this->assertSame('2025-03-15', $tractor->purchase_date->toDateString(), 'The real purchase date is kept.');
        $this->assertSame($today, $tractor->journalEntry->journal_date->toDateString());
        $this->assertSame('2025-03-15', $loan->start_date->toDateString());
        $this->assertSame($today, $loan->journalEntry->journal_date->toDateString());

        // A date inside the books is still booked on that date.
        $press = app(RecordFixedAsset::class)->handle(['name' => 'Press', 'category' => 'MACHINERY', 'purchase_date' => '2026-09-02', 'purchase_price' => '10000', 'funding' => 'EXISTING'], $this->admin->id);
        $this->assertSame('2026-09-02', $press->journalEntry->journal_date->toDateString());

        // Money paid out needs its own date in the books.
        $bank = CashBankAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1120'))->firstOrFail();
        $this->expectException(ValidationException::class);
        app(RecordFixedAsset::class)->handle(['name' => 'Old pump', 'category' => 'MACHINERY', 'purchase_date' => '2025-03-15', 'purchase_price' => '5000', 'funding' => 'CASH_BANK', 'cash_bank_account_id' => $bank->id, 'payment_method' => 'BANK_TRANSFER'], $this->admin->id);
    }

    #[Test]
    public function the_dashboard_shows_loans_still_owed_and_fixed_assets(): void
    {
        app(RecordFixedAsset::class)->handle(['name' => 'Tractor', 'category' => 'VEHICLE', 'purchase_date' => '2025-03-15', 'purchase_price' => '2500000', 'funding' => 'EXISTING'], $this->admin->id);
        app(RecordLoan::class)->handle(['lender' => 'Samurdhi bank', 'start_date' => '2025-03-15', 'funding' => 'EXISTING', 'amount' => '438000'], $this->admin->id);

        $this->actingAs($this->admin)->get(route('finance-dashboard.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('summary.position.loans_owed', '438000.000000')
                ->where('summary.position.fixed_assets', '2500000.000000'));
    }
}
