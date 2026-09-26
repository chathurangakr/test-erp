<?php

namespace Tests\Feature\Products;

use App\Models\Product;
use App\Models\UnitOfMeasure;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Database\Seeders\UnitOfMeasureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function nos(): UnitOfMeasure
    {
        return UnitOfMeasure::factory()->create(['code' => 'NOS', 'decimal_places' => 0]);
    }

    private function kg(): UnitOfMeasure
    {
        return UnitOfMeasure::factory()->weight()->create(['code' => 'KG']);
    }

    #[Test]
    public function authenticated_user_can_view_the_product_list(): void
    {
        $user = User::factory()->admin()->create();
        $product = Product::factory()->create([
            'code' => 'COCONUT',
            'primary_uom_id' => $this->nos()->id,
        ]);

        $response = $this->actingAs($user)->get(route('products.index'));

        $response->assertOk();
        $response->assertSee($product->code);
    }

    #[Test]
    public function guest_cannot_access_product_management(): void
    {
        $product = Product::factory()->create(['primary_uom_id' => $this->nos()->id]);

        $this->get(route('products.index'))->assertRedirect(route('login'));
        $this->get(route('products.create'))->assertRedirect(route('login'));
        $this->get(route('products.show', $product))->assertRedirect(route('login'));
        $this->get(route('products.edit', $product))->assertRedirect(route('login'));
        $this->post(route('products.store'), [])->assertRedirect(route('login'));
        $this->patch(route('products.status.update', $product))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function a_product_can_be_created(): void
    {
        $user = User::factory()->admin()->create();
        $nos = $this->nos();

        $response = $this->actingAs($user)->post(route('products.store'), [
            'code' => 'coconut',
            'name' => 'Whole Coconut',
            'description' => 'Purchased from suppliers.',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => null,
        ]);

        $response->assertSessionHasNoErrors();

        // The submitted lowercase code must be normalised to uppercase.
        $this->assertDatabaseHas('products', [
            'code' => 'COCONUT',
            'name' => 'Whole Coconut',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => null,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function a_duplicate_product_code_is_rejected(): void
    {
        $user = User::factory()->admin()->create();
        $nos = $this->nos();

        Product::factory()->create(['code' => 'COCONUT', 'primary_uom_id' => $nos->id]);

        $response = $this->actingAs($user)->post(route('products.store'), [
            // Lowercase, to prove the uniqueness check is not case-sensitive in effect.
            'code' => 'coconut',
            'name' => 'Duplicate Coconut',
            'primary_uom_id' => $nos->id,
        ]);

        $response->assertSessionHasErrors('code');
        $this->assertSame(1, Product::where('code', 'COCONUT')->count());
    }

    #[Test]
    public function the_primary_unit_of_measure_is_required(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this->actingAs($user)->post(route('products.store'), [
            'code' => 'NO-UOM',
            'name' => 'Product without a unit',
        ]);

        $response->assertSessionHasErrors('primary_uom_id');
        $this->assertDatabaseMissing('products', ['code' => 'NO-UOM']);
    }

    #[Test]
    public function the_secondary_unit_of_measure_may_be_null(): void
    {
        $user = User::factory()->admin()->create();
        $nos = $this->nos();

        $response = $this->actingAs($user)->post(route('products.store'), [
            'code' => 'DEHUSKED-COCONUT',
            'name' => 'Dehusked Coconut',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => null,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', [
            'code' => 'DEHUSKED-COCONUT',
            'secondary_uom_id' => null,
        ]);
    }

    #[Test]
    public function the_primary_and_secondary_units_of_measure_cannot_be_identical(): void
    {
        $user = User::factory()->admin()->create();
        $nos = $this->nos();

        $response = $this->actingAs($user)->post(route('products.store'), [
            'code' => 'SAME-UOM',
            'name' => 'Same units',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => $nos->id,
        ]);

        $response->assertSessionHasErrors('secondary_uom_id');
        $this->assertDatabaseMissing('products', ['code' => 'SAME-UOM']);
    }

    #[Test]
    public function a_product_can_be_updated(): void
    {
        $user = User::factory()->admin()->create();
        $nos = $this->nos();
        $kg = $this->kg();

        $product = Product::factory()->create([
            'code' => 'RAW-SHELL',
            'name' => 'Raw Shell',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => null,
        ]);

        $response = $this->actingAs($user)->put(route('products.update', $product), [
            'code' => 'RAW-SHELL',
            'name' => 'Raw Coconut Shell',
            'description' => 'Sold by quantity or by weight.',
            'primary_uom_id' => $nos->id,
            'secondary_uom_id' => $kg->id,
        ]);

        $response->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('Raw Coconut Shell', $product->name);
        $this->assertSame($kg->id, $product->secondary_uom_id);
    }

    #[Test]
    public function a_product_can_be_deactivated_and_reactivated(): void
    {
        $user = User::factory()->admin()->create();
        $product = Product::factory()->create([
            'primary_uom_id' => $this->nos()->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->patch(route('products.status.update', $product))
            ->assertSessionHasNoErrors();

        $this->assertFalse($product->fresh()->is_active);

        $this->actingAs($user)->patch(route('products.status.update', $product));

        $this->assertTrue($product->fresh()->is_active);

        // Deactivation must never remove the record.
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    #[Test]
    public function the_seeded_initial_products_exist(): void
    {
        $this->seed(UnitOfMeasureSeeder::class);
        $this->seed(ProductSeeder::class);

        $expected = [
            'COCONUT' => ['NOS', null],
            'DEHUSKED-COCONUT' => ['NOS', null],
            'RAW-HUSK' => ['NOS', null],
            'COPRA' => ['KG', null],
            'HUSK-7C' => ['KG', null],
            'HUSK-9C' => ['KG', null],
            'HUSK-10C' => ['KG', null],
            'RAW-SHELL' => ['NOS', 'KG'],
        ];

        $this->assertSame(count($expected), Product::count());

        foreach ($expected as $code => [$primary, $secondary]) {
            $product = Product::with(['primaryUom', 'secondaryUom'])
                ->where('code', $code)
                ->first();

            $this->assertNotNull($product, "Seeded product [{$code}] is missing.");
            $this->assertSame($primary, $product->primaryUom->code);
            $this->assertSame($secondary, $product->secondaryUom?->code);
            $this->assertTrue($product->is_active);
        }

        // Re-running the seeder must not duplicate anything.
        $this->seed(ProductSeeder::class);
        $this->assertSame(count($expected), Product::count());
    }

    #[Test]
    public function the_unit_of_measure_master_contains_only_nos_and_kg(): void
    {
        $this->seed(UnitOfMeasureSeeder::class);

        // The business uses exactly two units: NOS for countable items, KG for weight.
        // "Qty" is UI wording for a NOS count and must not become a separate unit.
        $this->assertSame(
            ['KG', 'NOS'],
            UnitOfMeasure::query()->orderBy('code')->pluck('code')->all(),
        );

        $this->assertSame(0, UnitOfMeasure::where('code', 'QTY')->count());
        $this->assertSame(0, UnitOfMeasure::where('code', 'NOS')->value('decimal_places'));
        $this->assertSame(3, UnitOfMeasure::where('code', 'KG')->value('decimal_places'));
    }

    #[Test]
    public function the_products_table_has_no_stock_quantity_column(): void
    {
        // Stock must be derived from inventory transactions, never stored on the product.
        // See docs/INVENTORY_RULES.md. This test guards that rule.
        $forbidden = [
            'current_stock',
            'stock',
            'stock_quantity',
            'quantity',
            'qty',
            'on_hand',
            'stock_on_hand',
            'balance',
        ];

        foreach ($forbidden as $column) {
            $this->assertFalse(
                Schema::hasColumn('products', $column),
                "The products table must not have a [{$column}] column — stock is derived from the inventory ledger."
            );
        }
    }
}
