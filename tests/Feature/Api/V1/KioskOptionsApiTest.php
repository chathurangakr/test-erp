<?php

namespace Tests\Feature\Api\V1;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The offline app's simple-mode data (GET /api/v1/kiosk/options) and the API's language.
 * See docs/API_V1.md §18.
 */
class KioskOptionsApiTest extends TestCase
{
    use RefreshDatabase;

    private Product $coconut;

    private Product $husk;

    private Product $chips;

    private Warehouse $main;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary_uom_id' => $nos->id]);
        Product::factory()->create(['code' => 'DEHUSKED-COCONUT', 'name' => 'Dehusked Coconut', 'primary_uom_id' => $nos->id]);
        $this->husk = Product::factory()->create(['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary_uom_id' => $nos->id, 'tracks_lots' => false]);

        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            $chip = Product::factory()->create(['code' => $code, 'primary_uom_id' => $kg->id, 'tracks_lots' => false]);
        }

        $this->chips = $chip;
        Product::factory()->create(['code' => 'COPRA', 'name' => 'Copra', 'primary_uom_id' => $kg->id]);
        Product::factory()->create(['code' => 'RAW-SHELL', 'name' => 'Raw Coconut Shell', 'primary_uom_id' => $nos->id, 'secondary_uom_id' => $kg->id]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->supplier = Supplier::factory()->create(['name' => 'Coconut Grower']);
        Customer::factory()->create(['name' => 'Chip Buyer']);
        $this->seedProductionRecipes();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function receiveCoconuts(string $quantity): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['mobile-api']);

        $this->postJson(route('api.v1.goods-receipts.record'), [
            'receipt_date' => '2026-09-19',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'transport_cost' => '0', 'loading_cost' => '0', 'other_cost' => '0',
            'lines' => [['product_id' => $this->coconut->id, 'quantity' => $quantity, 'unit_price' => '100']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    }

    /**
     * @return array<string, mixed>
     */
    private function optionsFor(User $user): array
    {
        Sanctum::actingAs($user, ['mobile-api']);

        return $this->getJson(route('api.v1.kiosk.options'))->assertOk()->json('data');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function product(array $data, Product $product): ?array
    {
        return collect($data['products'])->firstWhere('id', $product->id);
    }

    #[Test]
    public function a_storekeeper_gets_suppliers_customers_and_every_product_with_its_stock(): void
    {
        $this->receiveCoconuts('1200');
        $data = $this->optionsFor(User::factory()->withRoles('STOREKEEPER')->create());

        $this->assertSame(['receive', 'dispatch', 'stock'], $data['tasks']);
        $this->assertSame('2026-09-20', $data['business_today']);
        $this->assertSame(['Coconut Grower'], array_column($data['suppliers'], 'name'));
        $this->assertSame(['Chip Buyer'], array_column($data['customers'], 'name'));
        $this->assertTrue($data['warehouses'][0]['is_default']);
        $this->assertSame(['dehusking' => null, 'husk-chipping' => null, 'copra' => null], $data['recipes']);

        $coconut = $this->product($data, $this->coconut);
        $this->assertSame('1200.000', $coconut['total']);
        $this->assertSame([['warehouse_id' => $this->main->id, 'quantity' => '1200.000']], $coconut['stock']);
        $this->assertTrue($coconut['tracks_lots']);
        $this->assertCount(1, $coconut['lots']);

        // A product with no stock is still listed, so it can be received and dispatched offline.
        $husk = $this->product($data, $this->husk);
        $this->assertSame('0.000', $husk['total']);
        $this->assertSame([], $husk['stock']);
        $this->assertFalse($husk['tracks_lots']);
        $this->assertSame([], $husk['lots']);

        // What a chipping output's dispatch backflushes comes from the recipe.
        $this->assertSame($this->husk->id, $this->product($data, $this->chips)['backflush']['id']);
        $this->assertNull($coconut['backflush']);
    }

    #[Test]
    public function an_inactive_product_is_listed_only_while_it_holds_stock(): void
    {
        $this->receiveCoconuts('50');
        $this->coconut->update(['is_active' => false]);
        $this->husk->update(['is_active' => false]);

        $data = $this->optionsFor(User::factory()->withRoles('STOREKEEPER')->create());

        $this->assertFalse($this->product($data, $this->coconut)['is_active']);
        $this->assertNull($this->product($data, $this->husk));
    }

    #[Test]
    public function production_staff_get_the_recipes_but_no_suppliers_or_customers(): void
    {
        $data = $this->optionsFor(User::factory()->withRoles('PRODUCTION')->create());

        $this->assertSame(['dehusking', 'husk-chipping', 'copra', 'stock'], $data['tasks']);
        $this->assertNull($data['suppliers']);
        $this->assertNull($data['customers']);

        $dehusking = $data['recipes']['dehusking'];
        $this->assertSame($this->coconut->id, $dehusking['input']['id']);
        $this->assertNotNull($dehusking['labour']['default_rate']);
        $this->assertTrue(collect($dehusking['outputs'])->every(fn (array $output): bool => $output['derived']));

        $chipping = $data['recipes']['husk-chipping'];
        $this->assertFalse($chipping['consumes_input']);
        $this->assertTrue(collect($chipping['outputs'])->every(fn (array $output): bool => ! $output['derived']));

        $copra = collect($data['recipes']['copra']['outputs'])->keyBy('code');
        $this->assertFalse($copra['COPRA']['derived']);
        $this->assertTrue($copra['RAW-SHELL']['derived']);
        // Optional since copra recipe version 2: the factory has no scale for shell.
        $this->assertFalse($copra['RAW-SHELL']['requires_secondary_quantity']);
    }

    #[Test]
    public function someone_with_no_job_gets_nothing_but_an_empty_task_list(): void
    {
        $data = $this->optionsFor(User::factory()->create());

        $this->assertSame([], $data['tasks']);
        $this->assertNull($data['products']);
        $this->assertNull($data['warehouses']);
        $this->assertNull($data['suppliers']);
    }

    #[Test]
    public function the_api_answers_in_the_persons_language_unless_the_phone_asks_for_another(): void
    {
        $user = User::factory()->admin()->create(['locale' => 'si']);
        $this->coconut->update(['is_active' => false]);
        $body = [
            'receipt_date' => '2026-09-19',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->main->id,
            'transport_cost' => '0', 'loading_cost' => '0', 'other_cost' => '0',
            'lines' => [['product_id' => $this->coconut->id, 'quantity' => '1', 'unit_price' => '1']],
        ];

        $message = fn (array $headers): string => $this->postJson(
            route('api.v1.goods-receipts.record'),
            $body,
            ['Idempotency-Key' => (string) Str::uuid()] + $headers,
        )->assertUnprocessable()->json('errors')['lines.0.product_id'][0];

        Sanctum::actingAs($user, ['mobile-api']);
        $this->getJson(route('api.v1.auth.me'))->assertJsonPath('data.locale', 'si');

        // No language sent: the person's own choice.
        $this->assertSame('භාවිතයේ නැති භාණ්ඩයකට අලුත් තොග ලබාගත නොහැක.', $message(['Accept-Language' => '']));
        // The phone's language wins.
        $this->assertSame('An inactive product cannot receive new stock.', $message(['Accept-Language' => 'en-GB']));

        Sanctum::actingAs(User::factory()->admin()->create(['locale' => 'en']), ['mobile-api']);
        $this->assertSame('භාවිතයේ නැති භාණ්ඩයකට අලුත් තොග ලබාගත නොහැක.', $message(['Accept-Language' => 'si']));
    }
}
