<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Inventory\CreateInventoryLot;
use App\Actions\Inventory\PostInventoryTransaction;
use App\Data\Inventory\PendingInventoryLine;
use App\Enums\InventoryDirection;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductionStatus;
use App\Models\InventoryLot;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\UnitOfMeasure;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Production over the API — dehusking, husk chipping and copra through the same draft,
 * posting, reversal and cancellation actions as the website.
 */
class ProductionApiTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $main;

    private Product $coconut;

    private Product $dehusked;

    private Product $husk;

    private Product $copra;

    private Product $shell;

    /** @var array<string, Product> */
    private array $chips = [];

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $nos = UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
        $kg = UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);

        $this->coconut = Product::factory()->create(['code' => 'COCONUT', 'name' => 'Whole Coconut', 'primary_uom_id' => $nos->id]);
        $this->dehusked = Product::factory()->create(['code' => 'DEHUSKED-COCONUT', 'name' => 'Dehusked Coconut', 'primary_uom_id' => $nos->id]);
        $this->husk = Product::factory()->create(['code' => 'RAW-HUSK', 'name' => 'Raw Coconut Husk', 'primary_uom_id' => $nos->id]);

        foreach (['HUSK-7C', 'HUSK-9C', 'HUSK-10C'] as $code) {
            $this->chips[$code] = Product::factory()->create(['code' => $code, 'primary_uom_id' => $kg->id]);
        }

        $this->copra = Product::factory()->create(['code' => 'COPRA', 'name' => 'Copra', 'primary_uom_id' => $kg->id]);
        $this->shell = Product::factory()->create([
            'code' => 'RAW-SHELL',
            'name' => 'Raw Coconut Shell',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => $kg->id,
        ]);

        $this->main = Warehouse::factory()->default()->create(['code' => 'MAIN']);
        $this->seedProductionRecipes();

        $this->operator = User::factory()->admin()->create(['name' => 'Floor Operator']);
    }

    private function actAs(User $user): void
    {
        Sanctum::actingAs($user, ['mobile-api']);
    }

    /**
     * Receive stock of a product into a fresh lot and return the lot.
     */
    private function stockLot(Product $product, string $quantity, string $unitCost): InventoryLot
    {
        $lot = app(CreateInventoryLot::class)->handle($product);

        app(PostInventoryTransaction::class)->handle(InventoryTransactionType::PurchaseReceipt, [
            new PendingInventoryLine(
                productId: $product->id,
                warehouseId: $this->main->id,
                direction: InventoryDirection::In,
                quantity: $quantity,
                lotId: $lot->id,
                unitCost: $unitCost,
            ),
        ]);

        return $lot;
    }

    private function stock(Product $product): string
    {
        return app(StockQueryService::class)->quantityForProduct($product->id);
    }

    /**
     * @param  list<array<string, mixed>>  $outputs
     * @return string exact sum of the outputs' allocated cost
     */
    private function sumAllocated(array $outputs): string
    {
        return array_reduce($outputs, fn (string $sum, array $o): string => DecimalMath::add($sum, $o['allocated_cost']), '0');
    }

    // ------------------------------------------------------------- dehusking

    #[Test]
    public function dehusking_is_drafted_and_posted_with_the_1_1_1_outputs_derived(): void
    {
        $lot = $this->stockLot($this->coconut, '1000', '40.000000');
        $this->actAs($this->operator);

        $draft = $this->postJson(route('api.v1.dehusking.store'), [
            'production_date' => '2026-09-20',
            'warehouse_id' => $this->main->id,
            'lot_id' => $lot->id,
            'quantity' => '1000',
            'labour_rate' => '2.5',
            // Not part of the contract: the web ignores it, and so does the API.
            'outputs' => [['product_id' => $this->dehusked->id, 'quantity' => '5']],
        ])->assertCreated();

        $draft->assertJsonPath('data.status.value', 'DRAFT')
            ->assertJsonPath('data.production_type.value', 'DEHUSKING')
            ->assertJsonPath('data.inputs.0.quantity', '1000.000')
            ->assertJsonPath('data.inputs.0.total_cost', null)
            ->assertJsonPath('data.outputs', [])
            ->assertJsonPath('data.labour_rate', '2.500000')
            ->assertJsonPath('data.labour_cost', null)
            ->assertJsonPath('data.created_by.name', 'Floor Operator');
        $this->assertSame('1000.000', $this->stock($this->coconut), 'A draft moves no stock.');

        $posted = $this->postJson(route('api.v1.dehusking.post', $draft->json('data.id')))->assertOk();
        $body = $posted->json('data');

        $outputs = collect($body['outputs'])->keyBy(fn (array $o): string => $o['product']['code']);
        $this->assertSame('1000.000', $outputs['DEHUSKED-COCONUT']['quantity']);
        $this->assertSame('1000.000', $outputs['RAW-HUSK']['quantity']);
        $this->assertSame('POSTED', $body['status']['value']);
        $this->assertSame('Floor Operator', $body['posted_by']['name']);

        $this->assertSame('0.000', $this->stock($this->coconut));
        $this->assertSame('1000.000', $this->stock($this->dehusked));
        $this->assertSame('1000.000', $this->stock($this->husk));

        // The dehusked coconut carries the whole coconut value; the raw husk carries only the
        // labour (1,000 x 2.50). Input value + labour = output value, exactly.
        $this->assertSame('40000.000000', $body['inputs'][0]['total_cost']);
        $this->assertSame('2500.000000', $body['labour_cost']);
        $this->assertSame('40000.000000', $outputs['DEHUSKED-COCONUT']['allocated_cost']);
        $this->assertNull($outputs['DEHUSKED-COCONUT']['labour_cost']);
        $this->assertSame('2500.000000', $outputs['RAW-HUSK']['allocated_cost']);
        $this->assertSame('2500.000000', $outputs['RAW-HUSK']['labour_cost']);
        $this->assertSame(0, DecimalMath::compare($this->sumAllocated($body['outputs']), '42500.000000'));
        $this->assertSame(0, DecimalMath::compare(DecimalMath::add($body['input_value'], $body['labour_cost']), $body['output_value']));
    }

    #[Test]
    public function a_retried_post_is_a_409_and_moves_stock_once(): void
    {
        $lot = $this->stockLot($this->coconut, '500', '40.000000');
        $this->actAs($this->operator);

        $id = $this->postJson(route('api.v1.dehusking.store'), [
            'production_date' => '2026-09-20', 'warehouse_id' => $this->main->id, 'lot_id' => $lot->id, 'quantity' => '500', 'labour_rate' => '2',
        ])->json('data.id');

        $this->postJson(route('api.v1.dehusking.post', $id))->assertOk();
        $transactions = InventoryTransaction::count();

        $this->postJson(route('api.v1.dehusking.post', $id))
            ->assertStatus(409)
            ->assertJsonStructure(['message', 'errors' => ['status']]);

        $this->assertSame($transactions, InventoryTransaction::count());
        $this->assertSame('500.000', $this->stock($this->dehusked));
    }

    #[Test]
    public function a_dehusking_run_is_reversed_and_a_draft_cancelled(): void
    {
        $lot = $this->stockLot($this->coconut, '800', '40.000000');
        $this->actAs($this->operator);

        $posted = $this->postJson(route('api.v1.dehusking.store'), [
            'production_date' => '2026-09-20', 'warehouse_id' => $this->main->id, 'lot_id' => $lot->id, 'quantity' => '300', 'labour_rate' => '2',
        ])->json('data.id');
        $this->postJson(route('api.v1.dehusking.post', $posted))->assertOk();

        $this->postJson(route('api.v1.dehusking.reverse', $posted))
            ->assertOk()
            ->assertJsonPath('data.status.value', 'REVERSED')
            ->assertJsonPath('data.reversed_by.name', 'Floor Operator');

        $this->assertSame('800.000', $this->stock($this->coconut));
        $this->assertSame('0.000', $this->stock($this->dehusked));

        $draft = $this->postJson(route('api.v1.dehusking.store'), [
            'production_date' => '2026-09-20', 'warehouse_id' => $this->main->id, 'lot_id' => $lot->id, 'quantity' => '100', 'labour_rate' => '2',
        ])->json('data.id');

        $this->postJson(route('api.v1.dehusking.cancel', $draft))->assertOk()->assertJsonPath('data.status.value', 'CANCELLED');
        $this->postJson(route('api.v1.dehusking.cancel', $posted))->assertStatus(409);
    }

    #[Test]
    public function a_draft_is_updated_and_a_posted_run_cannot_be(): void
    {
        $lot = $this->stockLot($this->coconut, '1000', '40.000000');
        $this->actAs($this->operator);
        $payload = ['production_date' => '2026-09-20', 'warehouse_id' => $this->main->id, 'lot_id' => $lot->id, 'quantity' => '100', 'labour_rate' => '2'];

        $id = $this->postJson(route('api.v1.dehusking.store'), $payload)->json('data.id');

        $this->putJson(route('api.v1.dehusking.update', $id), ['quantity' => '250'] + $payload)
            ->assertOk()
            ->assertJsonPath('data.inputs.0.quantity', '250.000');

        $this->postJson(route('api.v1.dehusking.post', $id))->assertOk();
        $this->putJson(route('api.v1.dehusking.update', $id), $payload)->assertStatus(409);
    }

    #[Test]
    public function a_run_is_not_reachable_through_another_types_endpoint(): void
    {
        $this->actAs($this->operator);
        $copraRun = ProductionOrder::factory()->copra()->create();

        $this->getJson(route('api.v1.dehusking.show', $copraRun))->assertNotFound();
        $this->postJson(route('api.v1.dehusking.post', $copraRun))->assertNotFound();
        $this->getJson(route('api.v1.copra.show', $copraRun))->assertOk();

        ProductionOrder::factory()->create();
        $this->getJson(route('api.v1.copra.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.production_type.value', 'COPRA');
    }

    // ------------------------------------------------------------- husk chipping

    #[Test]
    public function husk_chipping_keeps_the_operators_measured_weights_exactly(): void
    {
        $this->stockLot($this->husk, '2000', '3.000000');
        $this->actAs($this->operator);

        $outputs = [
            ['product_id' => $this->chips['HUSK-7C']->id, 'quantity' => '150.125'],
            ['product_id' => $this->chips['HUSK-9C']->id, 'quantity' => '200.000'],
            ['product_id' => $this->chips['HUSK-10C']->id, 'quantity' => '280.750'],
        ];

        // A client still sending a husk lot or count believes husk is consumed here: refused.
        $this->postJson(route('api.v1.husk-chipping.store'), [
            'production_date' => '2026-09-20',
            'warehouse_id' => $this->main->id,
            'quantity' => '2000',
            'outputs' => $outputs,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $draft = $this->postJson(route('api.v1.husk-chipping.store'), [
            'production_date' => '2026-09-20',
            'warehouse_id' => $this->main->id,
            'outputs' => $outputs,
        ])->assertCreated();

        // A draft reports no share and no cost.
        $this->assertNull($draft->json('data.outputs.0.allocation_percent'));

        $body = $this->postJson(route('api.v1.husk-chipping.post', $draft->json('data.id')))->assertOk()->json('data');
        $byCode = collect($body['outputs'])->keyBy(fn (array $o): string => $o['product']['code']);

        $this->assertSame('150.125', $byCode['HUSK-7C']['quantity']);
        $this->assertSame('200.000', $byCode['HUSK-9C']['quantity']);
        $this->assertSame('280.750', $byCode['HUSK-10C']['quantity']);
        $this->assertSame('KG', $byCode['HUSK-7C']['uom']);
        $this->assertSame('280.750', $this->stock($this->chips['HUSK-10C']));

        // No husk taken at production: it is backflushed at delivery, so the chips carry no
        // material cost yet and the husk stock is untouched.
        $this->assertSame([], $body['inputs']);
        $this->assertTrue($body['raw_material_pending_backflush']);
        $this->assertSame(0, DecimalMath::compare($this->sumAllocated($body['outputs']), '0'));
        $this->assertSame('2000.000', $this->stock($this->husk));
    }

    #[Test]
    public function husk_chipping_waste_is_recorded_as_information_only(): void
    {
        $kg = UnitOfMeasure::where('code', 'KG')->firstOrFail();
        $this->actAs($this->operator);

        $this->postJson(route('api.v1.husk-chipping.store'), [
            'production_date' => '2026-09-20',
            'warehouse_id' => $this->main->id,
            'waste_quantity' => '12.5',
            'waste_uom_id' => $kg->id,
            'outputs' => [
                ['product_id' => $this->chips['HUSK-7C']->id, 'quantity' => '10'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.waste.quantity', '12.500')
            ->assertJsonPath('data.waste.uom', 'KG');
    }

    // ------------------------------------------------------------- copra

    #[Test]
    public function copra_derives_the_shell_count_and_keeps_both_measured_weights(): void
    {
        $lot = $this->stockLot($this->dehusked, '1000', '50.000000');
        $this->actAs($this->operator);

        $draft = $this->postJson(route('api.v1.copra.store'), [
            'production_date' => '2026-09-20',
            'warehouse_id' => $this->main->id,
            'lot_id' => $lot->id,
            'quantity' => '1000',
            'outputs' => [
                ['product_id' => $this->copra->id, 'quantity' => '200.000'],
                ['product_id' => $this->shell->id, 'secondary_quantity' => '420.250'],
            ],
        ])->assertCreated();

        $body = $this->postJson(route('api.v1.copra.post', $draft->json('data.id')))->assertOk()->json('data');
        $outputs = collect($body['outputs'])->keyBy(fn (array $o): string => $o['product']['code']);

        $this->assertSame('200.000', $outputs['COPRA']['quantity']);
        $this->assertSame('1000.000', $outputs['RAW-SHELL']['quantity'], 'One shell per coconut, derived.');
        $this->assertSame('420.250', $outputs['RAW-SHELL']['secondary_quantity']);
        $this->assertSame('NOS', $outputs['RAW-SHELL']['uom']);
        $this->assertSame('KG', $outputs['RAW-SHELL']['secondary_uom']);

        // Per-KG shell cost is shown for information and matches the model's own figure.
        $this->assertSame(
            DecimalMath::cost(DecimalMath::div($outputs['RAW-SHELL']['allocated_cost'], '420.250')),
            $outputs['RAW-SHELL']['informational_secondary_unit_cost'],
        );

        $this->assertSame('1000.000', $this->stock($this->shell));
        $this->assertSame('420.250', app(StockQueryService::class)->secondaryQuantityForProduct($this->shell->id));
        $this->assertSame(0, DecimalMath::compare($this->sumAllocated($body['outputs']), '50000.000000'));
    }

    #[Test]
    public function a_submitted_shell_count_is_refused(): void
    {
        $lot = $this->stockLot($this->dehusked, '100', '50.000000');
        $this->actAs($this->operator);

        $this->postJson(route('api.v1.copra.store'), [
            'production_date' => '2026-09-20',
            'warehouse_id' => $this->main->id,
            'lot_id' => $lot->id,
            'quantity' => '100',
            'outputs' => [
                ['product_id' => $this->copra->id, 'quantity' => '20'],
                ['product_id' => $this->shell->id, 'quantity' => '999', 'secondary_quantity' => '42'],
            ],
        ])->assertUnprocessable();

        $this->assertSame(0, ProductionOrder::count());
    }

    // ------------------------------------------------------------- permissions and format

    #[Test]
    public function the_production_role_posts_but_only_a_manager_reverses(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $lot = $this->stockLot($this->coconut, '200', '40.000000');

        $this->actAs(User::factory()->withRoles('PRODUCTION')->create());
        $id = $this->postJson(route('api.v1.dehusking.store'), [
            'production_date' => '2026-09-20', 'warehouse_id' => $this->main->id, 'lot_id' => $lot->id, 'quantity' => '200', 'labour_rate' => '2',
        ])->assertCreated()->json('data.id');

        $this->postJson(route('api.v1.dehusking.post', $id))->assertOk();
        $this->postJson(route('api.v1.dehusking.reverse', $id))->assertForbidden()->assertJsonStructure(['message']);
        $this->assertSame(ProductionStatus::Posted, ProductionOrder::find($id)->status);

        $this->actAs(User::factory()->withRoles('MANAGER')->create());
        $this->postJson(route('api.v1.dehusking.reverse', $id))->assertOk();
        $this->assertSame(ProductionStatus::Reversed, ProductionOrder::find($id)->status);
    }

    #[Test]
    public function a_sales_user_cannot_touch_production(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->actAs(User::factory()->withRoles('SALES')->create());

        $this->getJson(route('api.v1.copra.index'))->assertForbidden();
        $this->postJson(route('api.v1.copra.store'), [])->assertForbidden();
    }

    #[Test]
    public function quantities_and_costs_are_decimal_strings_and_times_iso_utc(): void
    {
        $lot = $this->stockLot($this->coconut, '10', '40.000000');
        $this->actAs($this->operator);

        $id = $this->postJson(route('api.v1.dehusking.store'), [
            'production_date' => '2026-09-20', 'warehouse_id' => $this->main->id, 'lot_id' => $lot->id, 'quantity' => '10', 'labour_rate' => '2',
        ])->json('data.id');
        $body = $this->postJson(route('api.v1.dehusking.post', $id))->json('data');

        $this->assertIsString($body['inputs'][0]['quantity']);
        $this->assertIsString($body['inputs'][0]['unit_cost']);
        $this->assertIsString($body['outputs'][0]['allocated_cost']);
        $this->assertIsString($body['outputs'][0]['allocation_percent']);
        $this->assertSame('2026-09-20', $body['production_date']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $body['posted_at']);
    }
}
