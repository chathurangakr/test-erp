<?php

namespace Tests\Feature\Masters;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'SUP-001',
            'name' => 'Coconut Growers Co-op',
            'payment_terms_days' => 30,
        ], $overrides);
    }

    #[Test]
    public function guest_cannot_access_supplier_management(): void
    {
        $supplier = Supplier::factory()->create();

        $this->get(route('suppliers.index'))->assertRedirect(route('login'));
        $this->get(route('suppliers.create'))->assertRedirect(route('login'));
        $this->get(route('suppliers.show', $supplier))->assertRedirect(route('login'));
        $this->get(route('suppliers.edit', $supplier))->assertRedirect(route('login'));
        $this->post(route('suppliers.store'), [])->assertRedirect(route('login'));
        $this->patch(route('suppliers.status.update', $supplier))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function authenticated_user_can_view_the_supplier_list(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create(['code' => 'SUP-VISIBLE']);

        $response = $this->actingAs($user)->get(route('suppliers.index'));

        $response->assertOk();
        $response->assertSee($supplier->code);
    }

    #[Test]
    public function a_supplier_can_be_created(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('suppliers.store'), $this->validPayload([
            'contact_person' => 'A. Perera',
            'phone' => '0771234567',
            'city' => 'Kurunegala',
        ]));

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('suppliers', [
            'code' => 'SUP-001',
            'name' => 'Coconut Growers Co-op',
            'contact_person' => 'A. Perera',
            'payment_terms_days' => 30,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function a_duplicate_supplier_code_is_rejected(): void
    {
        $user = User::factory()->create();
        Supplier::factory()->create(['code' => 'SUP-001']);

        $response = $this->actingAs($user)->post(
            route('suppliers.store'),
            $this->validPayload(['code' => 'sup-001'])
        );

        $response->assertSessionHasErrors('code');
        $this->assertSame(1, Supplier::where('code', 'SUP-001')->count());
    }

    #[Test]
    public function the_supplier_code_is_normalised_to_uppercase(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('suppliers.store'), $this->validPayload(['code' => '  sup-lower  ']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('suppliers', ['code' => 'SUP-LOWER']);
        $this->assertDatabaseMissing('suppliers', ['code' => 'sup-lower']);
    }

    #[Test]
    public function a_supplier_can_be_updated(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create([
            'code' => 'SUP-001',
            'name' => 'Old Name',
            'payment_terms_days' => 0,
        ]);

        $response = $this->actingAs($user)->put(
            route('suppliers.update', $supplier),
            $this->validPayload(['name' => 'New Name', 'payment_terms_days' => 45])
        );

        $response->assertSessionHasNoErrors();

        $supplier->refresh();
        $this->assertSame('New Name', $supplier->name);
        $this->assertSame(45, $supplier->payment_terms_days);
    }

    #[Test]
    public function a_supplier_can_be_deactivated_and_reactivated(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->patch(route('suppliers.status.update', $supplier))
            ->assertSessionHasNoErrors();

        $this->assertFalse($supplier->fresh()->is_active);

        $this->actingAs($user)->patch(route('suppliers.status.update', $supplier));

        $this->assertTrue($supplier->fresh()->is_active);

        // Deactivation must never remove the record.
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    #[Test]
    public function payment_terms_cannot_be_negative(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            route('suppliers.store'),
            $this->validPayload(['payment_terms_days' => -1])
        );

        $response->assertSessionHasErrors('payment_terms_days');
        $this->assertDatabaseMissing('suppliers', ['code' => 'SUP-001']);
    }

    #[Test]
    public function the_supplier_name_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('suppliers.store'), $this->validPayload(['name' => '']))
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function suppliers_can_be_searched(): void
    {
        $user = User::factory()->create();
        Supplier::factory()->create(['code' => 'SUP-AAA', 'name' => 'Findable Traders']);
        Supplier::factory()->create(['code' => 'SUP-BBB', 'name' => 'Hidden Traders']);

        $response = $this->actingAs($user)->get(route('suppliers.index', ['search' => 'Findable']));

        $response->assertOk();
        $response->assertSee('SUP-AAA');
        $response->assertDontSee('SUP-BBB');
    }

    #[Test]
    public function there_is_no_destroy_route_for_suppliers(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('suppliers.destroy'),
            'Suppliers must be deactivated, never deleted.'
        );
    }

    #[Test]
    public function the_suppliers_table_has_no_balance_or_accounting_columns(): void
    {
        // Balances belong to the finance module and must be derived from posted
        // transactions, never stored on the master record.
        foreach (['balance', 'current_balance', 'outstanding', 'total_purchases'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('suppliers', $column),
                "The suppliers table must not have a [{$column}] column."
            );
        }
    }
}
