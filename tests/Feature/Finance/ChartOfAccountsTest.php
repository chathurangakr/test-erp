<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\NormalBalance;
use App\Http\Requests\Accounting\StoreAccountRequest;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The chart of accounts.
 *
 * An account is a permanent label on financial history. These tests pin down that it stays
 * one: codes are unique, an account with postings cannot be deleted, and the accounts a
 * person may post to by hand are exactly the ones the chart says they may.
 */
class ChartOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_account_can_be_created(): void
    {
        $account = Account::factory()->create([
            'code' => '6400',
            'name' => 'Electricity',
            'account_type' => AccountType::Expense,
            'normal_balance' => NormalBalance::Debit,
        ]);

        $this->assertSame('6400', $account->code);
        $this->assertSame(AccountType::Expense, $account->account_type);
        $this->assertSame(NormalBalance::Debit, $account->normal_balance);
        $this->assertTrue($account->is_active);
        $this->assertFalse($account->is_control_account);
    }

    #[Test]
    public function an_account_code_is_unique_and_normalised_to_uppercase(): void
    {
        Account::factory()->create(['code' => 'ac-100']);

        $this->assertSame('AC-100', Account::first()->code);

        $this->expectException(QueryException::class);

        Account::factory()->create(['code' => 'AC-100']);
    }

    #[Test]
    public function an_account_may_have_a_parent(): void
    {
        $parent = Account::factory()->create(['code' => '6000', 'name' => 'Operating Expenses']);
        $child = Account::factory()->create(['code' => '6400', 'parent_id' => $parent->id]);

        $this->assertSame($parent->id, $child->parent->id);
        $this->assertTrue($parent->children->contains($child));
    }

    #[Test]
    public function an_inactive_account_does_not_accept_manual_posting(): void
    {
        $account = Account::factory()->inactive()->create();

        $this->assertFalse($account->acceptsManualPosting());
    }

    #[Test]
    public function a_control_account_does_not_accept_manual_posting(): void
    {
        $control = Account::factory()->control()->create(['code' => '1210', 'name' => 'Trade Receivables']);

        $this->assertTrue($control->is_control_account);
        $this->assertFalse($control->allow_manual_posting);
        $this->assertFalse($control->acceptsManualPosting());
    }

    #[Test]
    public function an_account_with_journal_history_cannot_be_deleted(): void
    {
        $account = Account::factory()->create();
        $entry = JournalEntry::factory()->create([
            'fiscal_period_id' => FiscalPeriod::factory()->create()->id,
        ]);
        JournalEntryLine::factory()->debit('100.000000')->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $account->id,
        ]);

        try {
            $account->delete();
            $this->fail('Expected the delete to be refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('journal history', $e->getMessage());
        }

        $this->assertNotNull(Account::find($account->id));
    }

    #[Test]
    public function an_account_with_children_cannot_be_deleted(): void
    {
        $parent = Account::factory()->create();
        Account::factory()->create(['parent_id' => $parent->id]);

        $this->expectException(RuntimeException::class);

        $parent->delete();
    }

    #[Test]
    public function every_account_type_has_a_conventional_normal_balance(): void
    {
        $this->assertSame(NormalBalance::Debit, AccountType::Asset->defaultNormalBalance());
        $this->assertSame(NormalBalance::Debit, AccountType::Expense->defaultNormalBalance());
        $this->assertSame(NormalBalance::Debit, AccountType::CostOfSales->defaultNormalBalance());

        $this->assertSame(NormalBalance::Credit, AccountType::Liability->defaultNormalBalance());
        $this->assertSame(NormalBalance::Credit, AccountType::Equity->defaultNormalBalance());
        $this->assertSame(NormalBalance::Credit, AccountType::Revenue->defaultNormalBalance());
    }

    // ------------------------------------------------------------- the seeded chart

    #[Test]
    public function the_initial_chart_of_accounts_seeds(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertSame(61, Account::count());

        foreach (['1110' => 'Cash on Hand', '1210' => 'Trade Receivables', '4140' => 'Copra Sales',
            '5140' => 'Copra COGS', '6400' => 'Electricity', '3200' => 'Retained Earnings',
            '2120' => 'Goods Received Not Invoiced', '4160' => 'Raw Husk Sales',
            '5160' => 'Raw Husk COGS', '1370' => 'Cocopeat Inventory', '4170' => 'Cocopeat Sales',
            '5170' => 'Cocopeat COGS', '6310' => 'Production Labour Absorbed'] as $code => $name) {
            $this->assertSame($name, Account::where('code', $code)->value('name'), $code);
        }
    }

    #[Test]
    public function the_seeded_chart_nests_correctly(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $cashOnHand = Account::where('code', '1110')->firstOrFail();
        $this->assertSame('1100', $cashOnHand->parent->code);
        $this->assertSame('1000', $cashOnHand->parent->parent->code);
        $this->assertNull($cashOnHand->parent->parent->parent);

        $copraCogs = Account::where('code', '5140')->firstOrFail();
        $this->assertSame('5100', $copraCogs->parent->code);
        $this->assertSame(AccountType::CostOfSales, $copraCogs->account_type);
    }

    #[Test]
    public function seeded_control_accounts_refuse_manual_posting(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        foreach (['1210', '1310', '1320', '1330', '1340', '1350', '1360', '2110', '2120'] as $code) {
            $account = Account::where('code', $code)->firstOrFail();

            $this->assertTrue($account->is_control_account, "{$code} should be a control account");
            $this->assertFalse($account->acceptsManualPosting(), "{$code} must refuse manual posting");
        }
    }

    #[Test]
    public function seeded_group_headers_refuse_manual_posting(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        // Posting to a header and to its children would double-count in any rolled-up report.
        foreach (['1000', '1100', '1200', '1300', '2000', '2100', '3000', '4000', '4100', '5000', '5100', '6000'] as $code) {
            $account = Account::where('code', $code)->firstOrFail();

            $this->assertFalse($account->allow_manual_posting, "{$code} is a header and must not be postable");
        }
    }

    #[Test]
    public function seeded_leaf_accounts_accept_manual_posting(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        foreach (['1110', '1120', '4110', '5110', '6400', '6500'] as $code) {
            $this->assertTrue(
                Account::where('code', $code)->firstOrFail()->acceptsManualPosting(),
                "{$code} should be postable",
            );
        }
    }

    /**
     * Goods Received Not Invoiced: the liability a receipt creates before the supplier's
     * invoice arrives. It is the system's to post to, never a person's.
     */
    #[Test]
    public function the_grni_account_is_a_liability_control_account_under_payables(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $grni = Account::where('code', '2120')->firstOrFail();

        $this->assertSame(AccountType::Liability, $grni->account_type);
        $this->assertSame(NormalBalance::Credit, $grni->normal_balance);
        $this->assertSame('2100', $grni->parent->code);
        $this->assertTrue($grni->is_control_account);
        $this->assertFalse($grni->acceptsManualPosting());
    }

    #[Test]
    public function raw_husk_has_its_own_revenue_and_cost_of_sales_accounts(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        $sales = Account::where('code', '4160')->firstOrFail();
        $cogs = Account::where('code', '5160')->firstOrFail();

        $this->assertSame(AccountType::Revenue, $sales->account_type);
        $this->assertSame('4100', $sales->parent->code);

        $this->assertSame(AccountType::CostOfSales, $cogs->account_type);
        $this->assertSame('5100', $cogs->parent->code);
    }

    #[Test]
    public function reseeding_the_chart_does_not_duplicate_accounts(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->assertSame(61, Account::count());
    }

    #[Test]
    public function the_seeded_chart_gives_every_account_the_conventional_normal_balance(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);

        foreach (Account::all() as $account) {
            $this->assertSame(
                $account->account_type->defaultNormalBalance(),
                $account->normal_balance,
                "{$account->code} has an unconventional normal balance",
            );
        }
    }

    #[Test]
    public function no_account_carries_a_stored_balance_column(): void
    {
        foreach (['balance', 'current_balance', 'debit_balance', 'credit_balance', 'opening_balance'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('accounts', $column),
                "accounts must not store a [{$column}] column — balances are derived.",
            );
        }
    }

    #[Test]
    public function the_base_currency_is_configuration_not_a_literal(): void
    {
        $this->assertSame('LKR', config('accounting.base_currency'));
        $this->assertSame(6, config('accounting.scale'));
    }

    #[Test]
    public function an_account_form_request_never_accepts_a_balance(): void
    {
        $rules = (new StoreAccountRequest)->rules();

        foreach (['balance', 'debit', 'credit', 'opening_balance'] as $field) {
            $this->assertArrayNotHasKey($field, $rules);
        }
    }
}
