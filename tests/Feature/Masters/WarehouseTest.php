<?php

namespace Tests\Feature\Masters;

use App\Actions\Warehouses\SetDefaultWarehouse;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\WarehouseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'WH-001',
            'name' => 'Secondary Store',
        ], $overrides);
    }

    #[Test]
    public function guest_cannot_access_warehouse_management(): void
    {
        $warehouse = Warehouse::factory()->create();

        $this->get(route('warehouses.index'))->assertRedirect(route('login'));
        $this->get(route('warehouses.create'))->assertRedirect(route('login'));
        $this->get(route('warehouses.show', $warehouse))->assertRedirect(route('login'));
        $this->get(route('warehouses.edit', $warehouse))->assertRedirect(route('login'));
        $this->post(route('warehouses.store'), [])->assertRedirect(route('login'));
        $this->patch(route('warehouses.status.update', $warehouse))->assertRedirect(route('login'));
        $this->patch(route('warehouses.default.update', $warehouse))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function authenticated_user_can_view_the_warehouse_list(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->create(['code' => 'WH-VISIBLE']);

        $response = $this->actingAs($user)->get(route('warehouses.index'));

        $response->assertOk();
        $response->assertSee($warehouse->code);
    }

    #[Test]
    public function a_warehouse_can_be_created(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('warehouses.store'), $this->validPayload([
            'description' => 'Overflow storage',
        ]));

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('warehouses', [
            'code' => 'WH-001',
            'name' => 'Secondary Store',
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    #[Test]
    public function a_duplicate_warehouse_code_is_rejected(): void
    {
        $user = User::factory()->create();
        Warehouse::factory()->create(['code' => 'WH-001']);

        $response = $this->actingAs($user)->post(
            route('warehouses.store'),
            $this->validPayload(['code' => 'wh-001'])
        );

        $response->assertSessionHasErrors('code');
        $this->assertSame(1, Warehouse::where('code', 'WH-001')->count());
    }

    #[Test]
    public function the_warehouse_code_is_normalised_to_uppercase(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('warehouses.store'), $this->validPayload(['code' => '  wh-lower  ']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('warehouses', ['code' => 'WH-LOWER']);
        $this->assertDatabaseMissing('warehouses', ['code' => 'wh-lower']);
    }

    #[Test]
    public function a_warehouse_can_be_updated(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->create(['code' => 'WH-001', 'name' => 'Old Name']);

        $response = $this->actingAs($user)->put(
            route('warehouses.update', $warehouse),
            $this->validPayload(['name' => 'New Name'])
        );

        $response->assertSessionHasNoErrors();
        $this->assertSame('New Name', $warehouse->fresh()->name);
    }

    #[Test]
    public function a_non_default_warehouse_can_be_deactivated_and_reactivated(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->create(['is_active' => true, 'is_default' => false]);

        $this->actingAs($user)
            ->patch(route('warehouses.status.update', $warehouse))
            ->assertSessionHasNoErrors();

        $this->assertFalse($warehouse->fresh()->is_active);

        $this->actingAs($user)->patch(route('warehouses.status.update', $warehouse));

        $this->assertTrue($warehouse->fresh()->is_active);
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
    }

    #[Test]
    public function the_main_warehouse_is_seeded(): void
    {
        $this->seed(WarehouseSeeder::class);

        $this->assertDatabaseHas('warehouses', [
            'code' => 'MAIN',
            'name' => 'Main Warehouse',
            'is_active' => true,
        ]);

        // Only MAIN is seeded — the example yards must not appear.
        $this->assertSame(1, Warehouse::count());

        foreach (['COCONUT-YARD', 'HUSK-YARD', 'COPRA-STORE', 'SHELL-YARD', 'FG-STORE'] as $code) {
            $this->assertSame(0, Warehouse::where('code', $code)->count());
        }

        // Re-running must not duplicate.
        $this->seed(WarehouseSeeder::class);
        $this->assertSame(1, Warehouse::count());
    }

    #[Test]
    public function the_main_warehouse_is_the_default(): void
    {
        $this->seed(WarehouseSeeder::class);

        $main = Warehouse::where('code', 'MAIN')->first();

        $this->assertNotNull($main);
        $this->assertTrue($main->is_default);
        $this->assertTrue($main->is_active);
        $this->assertSame($main->id, Warehouse::default()?->id);
    }

    #[Test]
    public function only_one_warehouse_can_be_the_default(): void
    {
        $this->seed(WarehouseSeeder::class);

        $main = Warehouse::where('code', 'MAIN')->firstOrFail();
        $other = Warehouse::factory()->create(['code' => 'WH-002']);

        app(SetDefaultWarehouse::class)->handle($other);

        $this->assertSame(1, Warehouse::where('is_default', true)->count());
        $this->assertTrue($other->fresh()->is_default);
        $this->assertFalse($main->fresh()->is_default);
    }

    #[Test]
    public function setting_the_default_through_the_route_clears_the_previous_default(): void
    {
        $user = User::factory()->create();
        $this->seed(WarehouseSeeder::class);

        $main = Warehouse::where('code', 'MAIN')->firstOrFail();
        $other = Warehouse::factory()->create(['code' => 'WH-002']);

        $this->actingAs($user)
            ->patch(route('warehouses.default.update', $other))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Warehouse::where('is_default', true)->count());
        $this->assertTrue($other->fresh()->is_default);
        $this->assertFalse($main->fresh()->is_default);
    }

    #[Test]
    public function an_inactive_warehouse_cannot_become_the_default(): void
    {
        $inactive = Warehouse::factory()->inactive()->create();

        $this->expectException(ValidationException::class);

        app(SetDefaultWarehouse::class)->handle($inactive);
    }

    #[Test]
    public function the_default_warehouse_cannot_be_deactivated(): void
    {
        $user = User::factory()->create();
        $this->seed(WarehouseSeeder::class);

        $main = Warehouse::where('code', 'MAIN')->firstOrFail();

        $this->actingAs($user)
            ->patch(route('warehouses.status.update', $main))
            ->assertSessionHasErrors('is_active');

        // It must remain active and default.
        $main->refresh();
        $this->assertTrue($main->is_active);
        $this->assertTrue($main->is_default);
    }

    #[Test]
    public function creating_a_warehouse_as_default_clears_the_previous_default(): void
    {
        $user = User::factory()->create();
        $this->seed(WarehouseSeeder::class);

        $main = Warehouse::where('code', 'MAIN')->firstOrFail();

        $this->actingAs($user)
            ->post(route('warehouses.store'), $this->validPayload(['is_default' => true]))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Warehouse::where('is_default', true)->count());
        $this->assertFalse($main->fresh()->is_default);
        $this->assertTrue(Warehouse::where('code', 'WH-001')->value('is_default'));
    }

    #[Test]
    public function there_is_no_destroy_route_for_warehouses(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('warehouses.destroy'),
            'Warehouses must be deactivated, never deleted.'
        );
    }
}
