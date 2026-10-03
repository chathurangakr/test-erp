<?php

namespace Tests\Feature\Kiosk;

use App\Enums\DeliveryStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\Permission;
use App\Enums\ProductionStatus;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Simple mode — "Done" saves and posts in one step, through the same actions as the full ERP.
 */
class KioskTest extends TestCase
{
    use RefreshDatabase;

    private Product $coconut;

    private Product $husk;

    private Product $shell;

    /** @var array<string, Product> */
    private array $chips = [];

    private Product $copraProduct;

    private Warehouse $main;

    private Supplier $supplier;

    private Customer $customer;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary_uom_id' => $nos->id]);
        Product::factory()->create(['code' => 'DEHUSKED-COCONUT', 'name' => 'Dehusked Coconut', 'primary_uom_id' => $nos->id]);
        $this->husk = Product::factory()->create(['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary_uom_id' => $nos->id]);

        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            $this->chips[$code] = Product::factory()->create(['code' => $code, 'primary_uom_id' => $kg->id]);
        }

        $this->copraProduct = Product::factory()->create(['code' => 'COPRA', 'name' => 'Copra', 'primary_uom_id' => $kg->id]);
        $this->shell = Product::factory()->create(['code' => 'RAW-SHELL', 'name' => 'Raw Coconut Shell', 'primary_uom_id' => $nos->id, 'secondary_uom_id' => $kg->id]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->supplier = Supplier::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->seedProductionRecipes();

        $this->worker = User::factory()->admin()->create(['name' => 'Floor Worker']);
    }

    private function stock(Product $product): string
    {
        return app(StockQueryService::class)->quantityForProduct($product->id);
    }

    /**
     * 1,000 coconuts at Rs. 100 each, received on the kiosk.
     */
    private function receiveCoconuts(string $quantity = '1000'): GoodsReceipt
    {
        $this->actingAs($this->worker)->post(route('kiosk.receive.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'lines' => [['product_id' => $this->coconut->id, 'quantity' => $quantity, 'unit_price' => '100']],
        ])->assertSessionHasNoErrors();

        return GoodsReceipt::query()->latest('id')->firstOrFail();
    }

    private function lotOf(Product $product): InventoryLot
    {
        return InventoryLot::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------- home and access

    #[Test]
    public function the_home_screen_shows_only_the_jobs_the_role_allows(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $tasks = fn (User $user) => $this->actingAs($user)->get(route('kiosk.home'))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('kiosk/Home'))
            ->inertiaProps('tasks');

        $this->assertSame(['receive', 'dispatch', 'stock'], $tasks(User::factory()->withRoles('STOREKEEPER')->create()));
        $this->assertSame(['dehusking', 'husk-chipping', 'copra', 'stock'], $tasks(User::factory()->withRoles('PRODUCTION')->create()));
        // Purchasing may draft a receipt but not post it, and Done posts — so no receive tile.
        $this->assertSame(['stock'], $tasks(User::factory()->withRoles('PURCHASING')->create()));
        $this->assertSame([], $tasks(User::factory()->create()));
    }

    #[Test]
    public function a_job_needs_both_the_create_and_the_post_permission(): void
    {
        $postOnly = User::factory()->withPermissions(Permission::PurchasingGrnPost)->create();

        $this->actingAs($postOnly)->get(route('kiosk.receive'))->assertOk();
        $this->actingAs($postOnly)->post(route('kiosk.receive.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'lines' => [['product_id' => $this->coconut->id, 'quantity' => '1', 'unit_price' => '1']],
        ])->assertForbidden();

        $this->actingAs(User::factory()->withPermissions(Permission::PurchasingGrnCreate)->create())
            ->get(route('kiosk.receive'))->assertForbidden();

        $this->assertSame(0, GoodsReceipt::count());
    }

    // ------------------------------------------------------------- receive

    #[Test]
    public function receiving_posts_at_once_with_the_typed_price_and_moves_stock_exactly_once(): void
    {
        $response = $this->actingAs($this->worker)->post(route('kiosk.receive.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'transport_cost' => '5000',
            'lines' => [['product_id' => $this->coconut->id, 'quantity' => '1000', 'unit_price' => '100']],
        ]);

        $receipt = GoodsReceipt::query()->sole();
        $response->assertRedirect(route('kiosk.receive.done', $receipt));

        $this->assertSame(GoodsReceiptStatus::Posted, $receipt->status);
        $this->assertSame($this->worker->id, $receipt->posted_by);
        $this->assertSame('1000.000', $this->stock($this->coconut));
        // 100,000 material + 5,000 transport = 105,000 → exactly the landed value.
        $this->assertSame('105000.000000', app(StockQueryService::class)->valueForProduct($this->coconut->id));

        $this->actingAs($this->worker)->get(route('kiosk.receive.done', $receipt))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('kiosk/Done')
                ->where('document.number', $receipt->grn_number)
                ->where('document.lines.0.quantity', '1000.000'));
    }

    #[Test]
    public function the_date_is_always_today_in_sri_lanka_whatever_the_client_sends(): void
    {
        // 02:00 on 27 September in Colombo; the UTC date is still the 26th.
        $this->travelTo(Carbon::parse('2026-09-26 20:30:00', 'UTC'));

        $this->actingAs($this->worker)->post(route('kiosk.receive.store'), [
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'receipt_date' => '2020-01-01',
            'lines' => [['product_id' => $this->coconut->id, 'quantity' => '10', 'unit_price' => '100']],
        ])->assertSessionHasNoErrors();

        $receipt = GoodsReceipt::query()->sole();
        $this->assertSame('2026-09-27', $receipt->receipt_date->toDateString());
        $this->assertStringStartsWith('GRN-20260927-', $receipt->grn_number);
    }

    // ------------------------------------------------------------- dispatch

    #[Test]
    public function dispatch_posts_at_once_and_requires_the_batch_to_be_chosen(): void
    {
        $this->receiveCoconuts();
        $lot = $this->lotOf($this->coconut);

        $payload = [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'lines' => [['product_id' => $this->coconut->id, 'quantity' => '200', 'pricing_basis' => 'PRIMARY', 'unit_price' => '130']],
        ];

        // No batch chosen: refused, and nothing at all is left behind.
        $this->actingAs($this->worker)->post(route('kiosk.dispatch.store'), $payload)->assertSessionHasErrors();
        $this->assertSame(0, Delivery::count());

        $payload['lines'][0]['lot_id'] = $lot->id;
        $this->actingAs($this->worker)->post(route('kiosk.dispatch.store'), $payload)->assertSessionHasNoErrors();

        $delivery = Delivery::query()->sole();
        $this->assertSame(DeliveryStatus::Posted, $delivery->status);
        $this->assertSame('800.000', $this->stock($this->coconut));
        $this->assertSame('26000.000000', $delivery->lines->first()->sales_value);
        $this->assertSame('20000.000000', $delivery->lines->first()->cost_of_goods_sold);
    }

    #[Test]
    public function a_failed_post_leaves_no_draft_behind(): void
    {
        $this->receiveCoconuts('100');

        $this->actingAs($this->worker)->post(route('kiosk.dispatch.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'lines' => [[
                'product_id' => $this->coconut->id,
                'lot_id' => $this->lotOf($this->coconut)->id,
                'quantity' => '500',
                'pricing_basis' => 'PRIMARY',
                'unit_price' => '130',
            ]],
        ])->assertSessionHasErrors();

        $this->assertSame(0, Delivery::count(), 'The save and the post are one transaction.');
        $this->assertSame('100.000', $this->stock($this->coconut));
    }

    // ------------------------------------------------------------- production

    #[Test]
    public function dehusking_husk_chipping_and_copra_post_through_the_recipe(): void
    {
        $this->receiveCoconuts();

        // Dehusking: the count and the labour rate are sent; outputs are derived 1:1:1.
        $this->actingAs($this->worker)->post(route('kiosk.dehusking.store'), [
            'warehouse_id' => $this->main->id,
            'lot_id' => $this->lotOf($this->coconut)->id,
            'quantity' => '400',
            'labour_rate' => '3',
        ])->assertSessionHasNoErrors();

        $this->assertSame('600.000', $this->stock($this->coconut));
        $this->assertSame('400.000', $this->stock($this->husk));
        // The husk carries the labour (400 x 3), not coconut value.
        $this->assertSame('1200.000000', app(StockQueryService::class)->valueForProduct($this->husk->id));

        // Husk chipping: only the measured outputs are sent; no husk leaves stock here.
        $this->actingAs($this->worker)->post(route('kiosk.husk-chipping.store'), [
            'warehouse_id' => $this->main->id,
            'outputs' => [
                ['product_id' => $this->chips['HUSK-7C']->id, 'quantity' => '60.125'],
                ['product_id' => $this->chips['HUSK-10C']->id, 'quantity' => '90.500'],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('60.125', $this->stock($this->chips['HUSK-7C']));
        $this->assertSame('0.000', $this->stock($this->chips['HUSK-9C']));
        $this->assertSame('400.000', $this->stock($this->husk), 'Raw husk is backflushed at delivery, not here.');

        // Copra: copra KG and shell KG measured; the shell COUNT is derived, never typed.
        $dehusked = Product::where('code', 'DEHUSKED-COCONUT')->firstOrFail();
        $this->actingAs($this->worker)->post(route('kiosk.copra.store'), [
            'warehouse_id' => $this->main->id,
            'lot_id' => $this->lotOf($dehusked)->id,
            'quantity' => '300',
            'outputs' => [
                ['product_id' => $this->copraProduct->id, 'quantity' => '75.250', 'secondary_quantity' => null],
                ['product_id' => $this->shell->id, 'quantity' => null, 'secondary_quantity' => '120.500'],
            ],
        ])->assertSessionHasNoErrors();

        $copra = ProductionOrder::query()->latest('id')->firstOrFail();
        $this->assertSame(ProductionStatus::Posted, $copra->status);
        $this->assertSame('300.000', $this->stock($this->shell));
        $this->assertSame('75.250', $this->stock($this->copraProduct));
        $this->assertSame($copra->inputValue(), $copra->outputValue(), 'Production conserves value exactly.');
    }

    #[Test]
    public function a_typed_shell_count_is_refused(): void
    {
        $this->receiveCoconuts();
        $this->actingAs($this->worker)->post(route('kiosk.dehusking.store'), [
            'warehouse_id' => $this->main->id, 'lot_id' => $this->lotOf($this->coconut)->id, 'quantity' => '100', 'labour_rate' => '3',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->worker)->post(route('kiosk.copra.store'), [
            'warehouse_id' => $this->main->id,
            'lot_id' => $this->lotOf(Product::where('code', 'DEHUSKED-COCONUT')->firstOrFail())->id,
            'quantity' => '100',
            'outputs' => [
                ['product_id' => $this->copraProduct->id, 'quantity' => '25'],
                ['product_id' => $this->shell->id, 'quantity' => '999', 'secondary_quantity' => '40'],
            ],
        ])->assertSessionHasErrors();

        $this->assertSame(0, ProductionOrder::query()->where('production_type', 'COPRA')->count());
    }

    // ------------------------------------------------------------- stock, language, start page

    #[Test]
    public function the_stock_screen_shows_quantities_from_the_ledger(): void
    {
        $this->receiveCoconuts('250');

        $this->actingAs($this->worker)->get(route('kiosk.stock'))
            ->assertInertia(fn (AssertableInertia $page) => $page->component('kiosk/Stock')
                ->where('products.0.total', '250.000')
                ->where('products.0.stores.0.quantity', '250.000'));
    }

    #[Test]
    public function the_language_switch_is_remembered_and_server_messages_follow_it(): void
    {
        $this->actingAs($this->worker)->get(route('kiosk.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('kiosk.locale', 'si'));

        $this->actingAs($this->worker)->post(route('kiosk.language'), ['locale' => 'en'])
            ->assertCookie('kiosk_locale', 'en', encrypted: false);

        $this->actingAs($this->worker)->withUnencryptedCookie('kiosk_locale', 'en')->get(route('kiosk.home'))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('kiosk.locale', 'en'));

        // A Sinhala tablet gets the stock error in Sinhala.
        $this->receiveCoconuts('10');
        $this->actingAs($this->worker)->withUnencryptedCookie('kiosk_locale', 'si')->post(route('kiosk.dispatch.store'), [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->main->id,
            'lines' => [['product_id' => $this->coconut->id, 'lot_id' => $this->lotOf($this->coconut)->id, 'quantity' => '50', 'pricing_basis' => 'PRIMARY', 'unit_price' => '1']],
        ]);

        $errors = session('errors');
        $messages = is_array($errors) ? json_encode($errors, JSON_UNESCAPED_UNICODE) : implode(' ', $errors->all());

        $this->assertStringContainsString('තොගය ප්‍රමාණවත් නැත', $messages);
    }

    #[Test]
    public function staff_marked_for_simple_mode_land_on_it_after_signing_in(): void
    {
        $staff = User::factory()->create(['start_in_simple_mode' => true]);
        $office = User::factory()->create();

        $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'password'])->assertRedirect(route('kiosk.home'));
        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $office->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
    }

    #[Test]
    public function an_administrator_can_switch_simple_mode_on_for_a_user(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();

        $this->actingAs($admin)->put(route('users.update', $staff), [
            'is_active' => true, 'role_ids' => [], 'start_in_simple_mode' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue($staff->fresh()->start_in_simple_mode);
    }

    #[Test]
    public function every_screen_word_exists_in_both_languages(): void
    {
        $source = file_get_contents(resource_path('js/kiosk/text.ts'));

        preg_match('/const en = \{(.*?)\} as const;/s', $source, $en);
        preg_match('/const si: Record<KioskTextKey, string> = \{(.*?)\};/s', $source, $si);

        preg_match_all("/'([a-zA-Z.\-]+)':/", $en[1], $enKeys);
        preg_match_all("/'([a-zA-Z.\-]+)':/", $si[1], $siKeys);

        $this->assertNotEmpty($enKeys[1]);
        $this->assertEqualsCanonicalizing($enKeys[1], $siKeys[1]);
    }
}
