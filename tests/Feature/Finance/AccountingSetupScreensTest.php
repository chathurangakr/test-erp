<?php

namespace Tests\Feature\Finance;

use App\Actions\Accounting\UpdateAccountingSettings;
use App\Models\Account;
use App\Models\AccountingSetting;
use App\Models\FiscalPeriod;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\ProductAccountMapping;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Database\Seeders\ProductionRecipeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The accounting setup screen.
 *
 * It is where operational accounting is switched on, so the assertions that matter are the
 * cautious ones: the cutover is never invented, an unmapped product is shown rather than
 * quietly assigned, and a mapping cannot point at the wrong class of account.
 */
class AccountingSetupScreensTest extends TestCase
{
    use RefreshDatabase;

    private Product $qa;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        foreach ([
            ['COCONUT', $nos], ['DEHUSKED-COCONUT', $nos], ['RAW-HUSK', $nos], ['COPRA', $kg],
            ['HUSK-7C', $kg], ['HUSK-9C', $kg], ['HUSK-10C', $kg],
        ] as [$code, $uom]) {
            Product::factory()->create(['code' => $code, 'primary_uom_id' => $uom->id]);
        }

        Product::factory()->create([
            'code' => 'RAW-SHELL',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => $kg->id,
        ]);

        // The hand-created QA product: deliberately never mapped.
        $this->qa = Product::factory()->create([
            'code' => 'QA-COCO-260913',
            'name' => 'QA TEST Coconut 2026-09-13',
            'primary_uom_id' => $nos->id,
        ]);

        $this->seed(ProductionRecipeSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
    }

    private function account(string $code): Account
    {
        return Account::where('code', $code)->firstOrFail();
    }

    #[Test]
    public function a_guest_cannot_reach_accounting_setup(): void
    {
        $this->get(route('accounting-setup.index'))->assertRedirect(route('login'));
        $this->patch(route('accounting-setup.settings'), [])->assertRedirect(route('login'));
        $this->patch(route('accounting-setup.mapping', $this->qa), [])->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function the_setup_screen_shows_the_configuration_and_the_mappings(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->get(route('accounting-setup.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('finance/accounting-setup/Index')
                ->where('settings.base_currency', 'LKR')
                // Never invented.
                ->where('settings.operational_accounting_start_date', null)
                ->where('settings.grni_account.code', '2120')
                ->where('settings.cutover_locked', false)
                ->where('referenceAccounts.trade_payables.code', '2110')
                ->where('referenceAccounts.trade_receivables.code', '1210')
                ->has('products', 9)
                // Only the QA product.
                ->where('unmappedCount', 1));
    }

    #[Test]
    public function the_qa_product_appears_as_unmapped_rather_than_being_assigned(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get(route('accounting-setup.index'));

        $products = collect($response->viewData('page')['props']['products']);
        $qa = $products->firstWhere('code', 'QA-COCO-260913');

        $this->assertFalse($qa['is_mapped']);
        $this->assertNull($qa['inventory_account']);
        $this->assertNull($qa['cogs_account']);

        $coconut = $products->firstWhere('code', 'COCONUT');
        $this->assertTrue($coconut['is_mapped']);
        $this->assertSame('1310', $coconut['inventory_account']['code']);
    }

    #[Test]
    public function the_cutover_date_can_be_set_and_is_then_reported(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->patch(route('accounting-setup.settings'), [
            'operational_accounting_start_date' => '2026-09-01',
            'grni_account_id' => $this->account('2120')->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            '2026-09-01',
            AccountingSetting::current()->operational_accounting_start_date->toDateString(),
        );

        $this->actingAs($user)
            ->get(route('accounting-setup.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.operational_accounting_start_date', '2026-09-01')
                ->where('settings.cutover_locked', false));
    }

    #[Test]
    public function the_screen_reports_the_cutover_as_locked_once_operational_journals_exist(): void
    {
        $user = User::factory()->admin()->create();
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);

        $period = FiscalPeriod::factory()->create([
            'name' => 'September 2026', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);
        JournalEntry::factory()->create([
            'fiscal_period_id' => $period->id,
            'source_type' => GoodsReceipt::class,
            'source_id' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('accounting-setup.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('settings.cutover_locked', true)
                ->where('settings.operational_journal_count', 1));

        $this->actingAs($user)->patch(route('accounting-setup.settings'), [
            'operational_accounting_start_date' => '2026-09-15',
        ])->assertSessionHasErrors('operational_accounting_start_date');
    }

    #[Test]
    public function a_product_can_be_mapped_through_the_screen(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->patch(route('accounting-setup.mapping', $this->qa), [
            'inventory_account_id' => $this->account('1310')->id,
            'cogs_account_id' => $this->account('5110')->id,
            'revenue_account_id' => $this->account('4110')->id,
        ])->assertSessionHasNoErrors();

        $mapping = ProductAccountMapping::where('product_id', $this->qa->id)->firstOrFail();

        $this->assertSame($this->account('1310')->id, $mapping->inventory_account_id);
        $this->assertSame($this->account('5110')->id, $mapping->cogs_account_id);
    }

    #[Test]
    public function a_mapping_cannot_point_at_an_inactive_account(): void
    {
        $user = User::factory()->admin()->create();
        $this->account('1310')->update(['is_active' => false]);

        $this->actingAs($user)->patch(route('accounting-setup.mapping', $this->qa), [
            'inventory_account_id' => $this->account('1310')->id,
            'cogs_account_id' => $this->account('5110')->id,
        ])->assertSessionHasErrors('inventory_account_id');
    }

    #[Test]
    public function the_screen_offers_only_accounts_of_the_right_class(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->get(route('accounting-setup.index'));
        $options = $response->viewData('page')['props']['accountOptions'];

        $inventoryCodes = collect($options['inventory'])->pluck('code');
        $cogsCodes = collect($options['cogs'])->pluck('code');

        // Inventory options are assets — and include the control accounts, because a
        // mapping is exactly how the system reaches them.
        $this->assertTrue($inventoryCodes->contains('1310'));
        $this->assertFalse($inventoryCodes->contains('5110'));

        $this->assertTrue($cogsCodes->contains('5110'));
        $this->assertFalse($cogsCodes->contains('1310'));

        $this->assertTrue(collect($options['liability'])->pluck('code')->contains('2120'));
    }
}
