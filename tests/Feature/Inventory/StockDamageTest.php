<?php

namespace Tests\Feature\Inventory;

use App\Actions\Accounting\UpdateAccountingSettings;
use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Actions\Inventory\RecordStockDamage;
use App\Actions\Inventory\ReverseStockDamage;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\Permission;
use App\Enums\StockDamageStatus;
use App\Models\Account;
use App\Models\FiscalPeriod;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockDamage;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingBalanceService;
use App\Services\Inventory\StockQueryService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\ProductAccountMappingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Damaged stock written off: the stock leaves at its weighted average, the loss is journalled,
 * and a mistake is undone by reversal — never by editing. See docs/INVENTORY_RULES.md §17.
 */
class StockDamageTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $main;

    private Product $bag;

    private Product $copra;

    private InventoryLot $copraLot;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->bag = Product::factory()->create(['code' => 'PP-BAG', 'primary_uom_id' => $nos->id, 'tracks_lots' => false]);
        $this->copra = Product::factory()->create(['code' => 'COPRA', 'primary_uom_id' => $kg->id, 'tracks_lots' => true]);
        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);

        $this->seed(ChartOfAccountsSeeder::class);
        $this->seed(ProductAccountMappingSeeder::class);
        FiscalPeriod::factory()->create(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
        app(UpdateAccountingSettings::class)->handle(['operational_accounting_start_date' => '2026-09-01']);

        $this->copraLot = app(CreateInventoryLot::class)->handle($this->copra);

        // 300 bags at Rs. 25 and 100 KG of copra at Rs. 400.
        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::OpeningBalance, [
            new PendingInventoryLine($this->bag->id, $this->main->id, InventoryDirection::In, '300.000', unitCost: '25.000000'),
            new PendingInventoryLine($this->copra->id, $this->main->id, InventoryDirection::In, '100.000', $this->copraLot->id, unitCost: '400.000000'),
        ], ['transaction_date' => '2026-09-01']);
    }

    /**
     * @param  list<array{product_id: int, lot_id?: int|null, quantity: string}>  $lines
     */
    private function writeOff(array $lines, string $reason = 'Bags torn in the store'): StockDamage
    {
        return app(RecordStockDamage::class)->handle([
            'damage_date' => '2026-09-15',
            'warehouse_id' => $this->main->id,
            'reason' => $reason,
            'lines' => $lines,
        ], User::factory()->create()->id);
    }

    private function balance(string $code): string
    {
        return app(AccountingBalanceService::class)->balance(Account::where('code', $code)->firstOrFail());
    }

    #[Test]
    public function damaged_stock_leaves_at_its_weighted_average_and_is_charged_to_the_loss_account(): void
    {
        $damage = $this->writeOff([['product_id' => $this->bag->id, 'quantity' => '40']]);

        $this->assertSame(StockDamageStatus::Posted, $damage->status);
        $this->assertMatchesRegularExpression('/^DMG-20260915-\d{6}$/', $damage->damage_number);
        $this->assertSame('260.000', app(StockQueryService::class)->quantityForProduct($this->bag->id));

        $line = $damage->lines()->first();
        $this->assertSame('25.000000', $line->unit_cost);
        $this->assertSame('1000.000000', $line->total_cost);

        $transaction = $damage->inventoryTransaction;
        $this->assertSame(InventoryTransactionType::AdjustmentOut, $transaction->transaction_type);
        $this->assertSame(StockDamage::class, $transaction->source_type);

        // Dr 6900 Stock Damage and Losses / Cr 1380 Packing Materials Inventory. (The opening
        // balance itself raised no journal, so only the write-off shows on 1380.)
        $this->assertSame('1000.000000', $this->balance('6900'));
        $this->assertSame('-1000.000000', $this->balance('1380'));
        $this->assertSame(1, JournalEntry::where('source_type', StockDamage::class)->count());
    }

    #[Test]
    public function a_lot_tracked_product_needs_the_lot_it_came_from(): void
    {
        try {
            $this->writeOff([['product_id' => $this->copra->id, 'quantity' => '5']], 'Wet copra');
            $this->fail('A lot-tracked product was written off without its lot.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines.0.lot_id', $exception->errors());
        }

        $damage = $this->writeOff([['product_id' => $this->copra->id, 'lot_id' => $this->copraLot->id, 'quantity' => '5']], 'Wet copra');

        $this->assertSame('2000.000000', $damage->lines()->first()->total_cost);
        $this->assertSame('95.000', app(StockQueryService::class)->quantityForLot($this->copraLot->id));
    }

    #[Test]
    public function writing_off_more_than_is_in_stock_fails_and_leaves_nothing_behind(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->writeOff([
                ['product_id' => $this->bag->id, 'quantity' => '10'],
                ['product_id' => $this->copra->id, 'lot_id' => $this->copraLot->id, 'quantity' => '101'],
            ]);
        } finally {
            $this->assertSame(0, StockDamage::count());
            $this->assertSame(1, InventoryTransaction::count());
            $this->assertSame('300.000', app(StockQueryService::class)->quantityForProduct($this->bag->id));
            $this->assertSame(0, JournalEntry::count());
        }
    }

    #[Test]
    public function a_reversal_brings_the_stock_back_and_mirrors_the_journal(): void
    {
        $damage = $this->writeOff([['product_id' => $this->bag->id, 'quantity' => '40']]);

        $reversed = app(ReverseStockDamage::class)->handle($damage);

        $this->assertSame(StockDamageStatus::Reversed, $reversed->status);
        $this->assertNotNull($reversed->reversal_inventory_transaction_id);
        $this->assertSame('300.000', app(StockQueryService::class)->quantityForProduct($this->bag->id));
        $this->assertSame('7500.000000', app(StockQueryService::class)->valueForProduct($this->bag->id));
        $this->assertSame('0.000000', $this->balance('6900'));

        $this->expectException(ValidationException::class);
        app(ReverseStockDamage::class)->handle($reversed);
    }

    #[Test]
    public function a_write_off_cannot_be_edited_or_deleted(): void
    {
        $damage = $this->writeOff([['product_id' => $this->bag->id, 'quantity' => '1']]);

        try {
            $damage->update(['reason' => 'Something else']);
            $this->fail('A write-off was edited.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->expectException(RuntimeException::class);
        $damage->delete();
    }

    #[Test]
    public function the_screens_record_and_show_a_write_off_for_a_permitted_user(): void
    {
        $user = User::factory()->withPermissions(Permission::InventoryView, Permission::InventoryDamageRecord)->create();

        $this->actingAs($user)->get(route('stock-damages.create'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('inventory/damages/Create')
                ->has('balances', 2)
                ->where('lots.0.id', $this->copraLot->id));

        $this->actingAs($user)->post(route('stock-damages.store'), [
            'damage_date' => '2026-09-15',
            'warehouse_id' => $this->main->id,
            'reason' => 'Rats in the store',
            'lines' => [['product_id' => $this->bag->id, 'quantity' => '3']],
        ])->assertRedirect();

        $damage = StockDamage::firstOrFail();

        $this->actingAs($user)->get(route('stock-damages.show', $damage))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('inventory/damages/Show')
                ->where('damage.total_cost', '75.000000')
                ->where('damage.can_reverse', true));

        $this->actingAs($user)->get(route('stock-damages.index'))->assertOk();

        // Recording does not include reversing.
        $this->actingAs($user)->patch(route('stock-damages.reverse', $damage))->assertForbidden();
    }

    #[Test]
    public function a_viewer_cannot_write_stock_off(): void
    {
        $viewer = User::factory()->withPermissions(Permission::InventoryView)->create();

        $this->actingAs($viewer)->get(route('stock-damages.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('stock-damages.store'), [])->assertForbidden();
    }
}
