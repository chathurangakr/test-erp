<?php

namespace Tests\Feature\Assets;

use App\Actions\Assets\RecordAssetMaintenance;
use App\Actions\Assets\RecordFixedAsset;
use App\Actions\Assets\ReverseAssetMaintenance;
use App\Actions\Assets\ReverseAssetSale;
use App\Actions\Assets\ReverseFixedAsset;
use App\Actions\Assets\SellFixedAsset;
use App\Actions\Loans\RecordLoanPayment;
use App\Actions\Loans\ReverseLoan;
use App\Enums\FixedAssetStatus;
use App\Enums\LoanDocumentStatus;
use App\Enums\LoanFunding;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\CashBankAccount;
use App\Models\FiscalPeriod;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Loans\LoanBalances;
use App\Support\DecimalMath;
use Database\Seeders\CashBankAccountSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Fixed assets (owner's rules, 2026-10-25): carried at what they cost — no depreciation — until
 * sold, where profit or loss is sale price − cost; bought for cash, on a loan or already owned;
 * with a maintenance log charged to repairs.
 */
class FixedAssetsTest extends TestCase
{
    use RefreshDatabase;

    private CashBankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(CashBankAccountSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        $this->bank = CashBankAccount::query()->whereHas('account', fn ($q) => $q->where('code', '1120'))->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function asset(array $overrides = []): FixedAsset
    {
        return app(RecordFixedAsset::class)->handle(array_merge([
            'name' => 'Dehusking machine',
            'category' => 'MACHINERY',
            'purchase_date' => '2026-09-02',
            'purchase_price' => '400000',
            'funding' => 'CASH_BANK',
            'cash_bank_account_id' => $this->bank->id,
            'payment_method' => 'BANK_TRANSFER',
        ], $overrides), User::factory()->create()->id);
    }

    private function balance(string $code): string
    {
        return app(AccountingBalanceService::class)->balance(Account::where('code', $code)->firstOrFail());
    }

    private function sell(FixedAsset $asset, string $price): FixedAsset
    {
        return app(SellFixedAsset::class)->handle($asset, array_filter([
            'sold_date' => '2026-09-20',
            'sale_price' => $price,
            'buyer' => 'A neighbour',
            'cash_bank_account_id' => DecimalMath::isPositive($price) ? $this->bank->id : null,
            'payment_method' => DecimalMath::isPositive($price) ? 'BANK_TRANSFER' : null,
        ], fn ($value): bool => $value !== null));
    }

    #[Test]
    public function an_asset_paid_in_full_moves_money_from_the_bank_into_fixed_assets(): void
    {
        $bankBefore = $this->balance('1120');

        $this->asset();

        $this->assertSame('400000.000000', $this->balance('1500'));
        $this->assertSame(DecimalMath::cost(DecimalMath::sub($bankBefore, '400000')), $this->balance('1120'));
    }

    #[Test]
    public function an_asset_on_a_loan_opens_the_loan_for_what_was_not_paid_down(): void
    {
        $lorry = $this->asset([
            'name' => 'Lorry', 'category' => 'VEHICLE', 'purchase_price' => '1200000', 'funding' => 'LOAN',
            'down_payment' => '200000', 'lender' => 'LB Finance', 'installment_amount' => '45000', 'due_day' => 10,
        ]);

        $loan = $lorry->loan;
        $this->assertSame(LoanFunding::Asset, $loan->funding);
        $this->assertSame('1000000.000000', app(LoanBalances::class)->outstanding($loan));
        $this->assertSame($lorry->journal_entry_id, $loan->journal_entry_id);
        $this->assertSame('1200000.000000', $this->balance('1500'));
        $this->assertSame('1000000.000000', $this->balance('2400'));

        // Its installments work like any other loan's — but it is undone from the asset.
        app(RecordLoanPayment::class)->handle($loan, ['payment_date' => '2026-09-10', 'amount' => '45000', 'interest_amount' => '9000', 'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'BANK_TRANSFER']);
        $this->assertSame('964000.000000', $this->balance('2400'));

        $this->expectException(ValidationException::class);
        app(ReverseLoan::class)->handle($loan);
    }

    #[Test]
    public function an_asset_already_owned_comes_in_at_cost_against_opening_balances(): void
    {
        $this->asset(['funding' => 'EXISTING', 'cash_bank_account_id' => null, 'payment_method' => null]);

        $this->assertSame('400000.000000', $this->balance('1500'));
        $this->assertSame('400000.000000', $this->balance('3300'));
    }

    #[Test]
    public function a_sale_shows_profit_or_loss_against_cost_and_scrapping_is_a_sale_at_nothing(): void
    {
        $this->sell($this->asset(), '450000');
        $this->assertSame('50000.000000', $this->balance('4900'));

        $this->sell($this->asset(['name' => 'Old pump', 'purchase_price' => '80000']), '60000');
        $this->assertSame('20000.000000', $this->balance('6960'));

        $scrapped = $this->sell($this->asset(['name' => 'Broken scale', 'purchase_price' => '15000']), '0');
        $this->assertSame(FixedAssetStatus::Sold, $scrapped->status);
        $this->assertSame('35000.000000', $this->balance('6960'));
        $this->assertSame('0.000000', $this->balance('1500'));
        $this->assertTrue(app(AccountingBalanceService::class)->trialBalance()['balanced']);
    }

    #[Test]
    public function a_reversed_sale_puts_the_asset_back_in_use(): void
    {
        $asset = $this->sell($this->asset(), '300000');

        $back = app(ReverseAssetSale::class)->handle($asset);

        $this->assertSame(FixedAssetStatus::InUse, $back->status);
        $this->assertNull($back->sold_date);
        $this->assertSame('400000.000000', $this->balance('1500'));
        $this->assertSame('0.000000', $this->balance('6960'));
    }

    #[Test]
    public function maintenance_is_charged_to_repairs_and_kept_as_the_assets_history(): void
    {
        $asset = $this->asset();

        $service = app(RecordAssetMaintenance::class)->handle($asset, [
            'maintenance_date' => '2026-09-12', 'work_done' => 'Belt replaced', 'done_by' => 'Sunil', 'cost' => '7500',
            'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'BANK_TRANSFER',
        ]);
        $free = app(RecordAssetMaintenance::class)->handle($asset, ['maintenance_date' => '2026-09-13', 'work_done' => 'Checked under warranty', 'cost' => '0']);

        $this->assertSame('7500.000000', $this->balance('6600'));
        $this->assertNull($free->journal_entry_id);
        $this->assertSame(2, $asset->maintenances()->count());

        // Maintenance standing blocks reversing the asset; reversing it releases that.
        try {
            app(ReverseFixedAsset::class)->handle($asset);
            $this->fail('An asset with maintenance was reversed.');
        } catch (ValidationException) {
        }

        app(ReverseAssetMaintenance::class)->handle($service);
        app(ReverseAssetMaintenance::class)->handle($free);
        $this->assertSame('0.000000', $this->balance('6600'));
        $this->assertSame(FixedAssetStatus::Reversed, app(ReverseFixedAsset::class)->handle($asset)->status);
    }

    #[Test]
    public function reversing_an_asset_bought_on_a_loan_undoes_the_loan_too(): void
    {
        $lorry = $this->asset(['purchase_price' => '1000000', 'funding' => 'LOAN', 'down_payment' => '0', 'lender' => 'LB Finance', 'cash_bank_account_id' => null, 'payment_method' => null]);

        app(ReverseFixedAsset::class)->handle($lorry);

        $this->assertSame(LoanDocumentStatus::Reversed, $lorry->loan->fresh()->status);
        $this->assertSame('0.000000', $this->balance('2400'));
        $this->assertSame('0.000000', $this->balance('1500'));
        $this->assertSame(2, JournalEntry::count());
    }

    #[Test]
    public function the_purchase_figures_cannot_be_edited(): void
    {
        $asset = $this->asset();
        $asset->update(['name' => 'Dehusking machine No. 2']);

        $this->expectException(RuntimeException::class);
        $asset->update(['purchase_price' => '1']);
    }

    #[Test]
    public function the_screens_add_an_asset_and_log_its_maintenance_for_permitted_users(): void
    {
        $user = User::factory()->withPermissions(Permission::AssetsView, Permission::AssetsManage, Permission::AssetsMaintenanceRecord)->create();

        $this->actingAs($user)->post(route('assets.store'), [
            'name' => 'Generator', 'category' => 'EQUIPMENT', 'purchase_date' => '2026-09-05', 'purchase_price' => '250000',
            'funding' => 'CASH_BANK', 'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'BANK_TRANSFER',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $asset = FixedAsset::firstOrFail();
        $this->actingAs($user)->post(route('assets.maintenance.store', $asset), [
            'maintenance_date' => '2026-09-15', 'work_done' => 'Oil change', 'cost' => '3000',
            'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'CASH',
        ])->assertSessionHasErrors('cash_bank_account_id'); // cash out of a bank account is refused

        $this->actingAs($user)->post(route('assets.maintenance.store', $asset), [
            'maintenance_date' => '2026-09-15', 'work_done' => 'Oil change', 'cost' => '3000',
            'cash_bank_account_id' => $this->bank->id, 'payment_method' => 'BANK_TRANSFER',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('assets.show', $asset))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('assets/Show')
                ->where('asset.maintenance_cost', '3000.000000')
                ->has('asset.maintenances', 1));
        $this->actingAs($user)->get(route('assets.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('totalInUse', '250000.000000'));

        // Recording does not include selling or reversing.
        $this->actingAs($user)->post(route('assets.sell', $asset), ['sold_date' => '2026-09-20', 'sale_price' => '0'])->assertForbidden();
        $this->actingAs($user)->patch(route('assets.reverse', $asset))->assertForbidden();
    }
}
