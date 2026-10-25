<?php

namespace Tests\Feature\Finance;

use App\Actions\Assets\RecordFixedAsset;
use App\Actions\Cash\PostFundTransfer;
use App\Actions\Cash\SaveFundTransfer;
use App\Actions\Loans\RecordLoan;
use App\Actions\Loans\RecordLoanPayment;
use App\Actions\Payables\RecordSupplierAdvance;
use App\Actions\Receivables\RecordCustomerAdvance;
use App\Enums\Permission;
use App\Models\CashBankAccount;
use App\Models\Customer;
use App\Models\FiscalPeriod;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\CashFlowService;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cash flow statement (2026-10-25): every cash and bank movement classified by its document
 * into operating, investing and financing; transfers between the factory's own accounts left out;
 * start + net change = end.
 */
class CashFlowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function money_in_and_out_is_classified_and_agrees_with_the_books(): void
    {
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $user = User::factory()->admin()->create();
        $bank = CashBankAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1120'))->firstOrFail();
        $cash = CashBankAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1110'))->firstOrFail();
        $byBank = ['cash_bank_account_id' => $bank->id, 'payment_method' => 'BANK_TRANSFER'];

        app(RecordCustomerAdvance::class)->handle($byBank + ['customer_id' => Customer::factory()->create()->id, 'advance_date' => '2026-09-02', 'amount' => '100000']);
        app(RecordSupplierAdvance::class)->handle($byBank + ['supplier_id' => Supplier::factory()->create()->id, 'advance_date' => '2026-09-03', 'amount' => '30000']);
        $loan = app(RecordLoan::class)->handle(['lender' => 'Bank', 'start_date' => '2026-09-04', 'funding' => 'RECEIVED', 'amount' => '300000', 'cash_bank_account_id' => $bank->id], $user->id);
        app(RecordLoanPayment::class)->handle($loan, $byBank + ['payment_date' => '2026-09-20', 'amount' => '25000', 'interest_amount' => '7500']);
        app(RecordFixedAsset::class)->handle($byBank + ['name' => 'Tractor', 'category' => 'VEHICLE', 'purchase_date' => '2026-09-05', 'purchase_price' => '220000', 'funding' => 'CASH_BANK'], $user->id);
        $transfer = app(SaveFundTransfer::class)->handle(['transfer_date' => '2026-09-06', 'from_cash_bank_account_id' => $bank->id, 'to_cash_bank_account_id' => $cash->id, 'amount' => '10000'], $user->id);
        app(PostFundTransfer::class)->handle($transfer, $user->id);

        $statement = app(CashFlowService::class)->statement(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
        $rows = collect($statement['sections'])->mapWithKeys(fn (array $s) => [$s['key'] => collect($s['rows'])->pluck('amount', 'label')->all() + ['total' => $s['total']]]);

        $this->assertSame([
            'Advances from customers' => '100000.000000',
            'Advances to suppliers' => '-30000.000000',
            'Loan interest paid' => '-7500.000000',
            'total' => '62500.000000',
        ], $rows['operating']);
        $this->assertSame(['Fixed assets bought' => '-220000.000000', 'total' => '-220000.000000'], $rows['investing']);
        $this->assertSame(['Loans received' => '300000.000000', 'Loan repayments' => '-17500.000000', 'total' => '282500.000000'], $rows['financing']);
        $this->assertFalse($rows->has('other'), 'The cash-to-bank transfer is not a flow.');

        $this->assertSame('125000.000000', $statement['net_change']);
        $this->assertSame('0.000000', $statement['opening']);
        $this->assertSame('125000.000000', $statement['closing']);
        $this->assertTrue($statement['balanced']);

        $this->actingAs(User::factory()->withPermissions(Permission::ReportsFinance)->create())
            ->get(route('reports.cash-flow', ['from' => '2026-09-01', 'to' => '2026-09-30']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('finance/reports/CashFlow')->where('statement.closing', '125000.000000'));
        $this->actingAs(User::factory()->withPermissions(Permission::FinanceView)->create())->get(route('reports.cash-flow'))->assertForbidden();
    }
}
