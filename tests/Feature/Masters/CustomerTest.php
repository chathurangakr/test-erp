<?php

namespace Tests\Feature\Masters;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'CUS-001',
            'name' => 'Island Copra Buyers',
            'credit_limit' => '250000.00',
            'credit_days' => 30,
        ], $overrides);
    }

    #[Test]
    public function guest_cannot_access_customer_management(): void
    {
        $customer = Customer::factory()->create();

        $this->get(route('customers.index'))->assertRedirect(route('login'));
        $this->get(route('customers.create'))->assertRedirect(route('login'));
        $this->get(route('customers.show', $customer))->assertRedirect(route('login'));
        $this->get(route('customers.edit', $customer))->assertRedirect(route('login'));
        $this->post(route('customers.store'), [])->assertRedirect(route('login'));
        $this->patch(route('customers.status.update', $customer))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function authenticated_user_can_view_the_customer_list(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['code' => 'CUS-VISIBLE']);

        $response = $this->actingAs($user)->get(route('customers.index'));

        $response->assertOk();
        $response->assertSee($customer->code);
    }

    #[Test]
    public function a_customer_can_be_created(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('customers.store'), $this->validPayload([
            'contact_person' => 'S. Fernando',
            'city' => 'Colombo',
        ]));

        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'code' => 'CUS-001',
            'name' => 'Island Copra Buyers',
            'credit_limit' => '250000.00',
            'credit_days' => 30,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function a_duplicate_customer_code_is_rejected(): void
    {
        $user = User::factory()->create();
        Customer::factory()->create(['code' => 'CUS-001']);

        $response = $this->actingAs($user)->post(
            route('customers.store'),
            $this->validPayload(['code' => 'cus-001'])
        );

        $response->assertSessionHasErrors('code');
        $this->assertSame(1, Customer::where('code', 'CUS-001')->count());
    }

    #[Test]
    public function the_customer_code_is_normalised_to_uppercase(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('customers.store'), $this->validPayload(['code' => '  cus-lower  ']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', ['code' => 'CUS-LOWER']);
        $this->assertDatabaseMissing('customers', ['code' => 'cus-lower']);
    }

    #[Test]
    public function a_customer_can_be_updated(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create([
            'code' => 'CUS-001',
            'name' => 'Old Name',
            'credit_limit' => 0,
        ]);

        $response = $this->actingAs($user)->put(
            route('customers.update', $customer),
            $this->validPayload(['name' => 'New Name', 'credit_limit' => '500000.00'])
        );

        $response->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('New Name', $customer->name);
        $this->assertSame('500000.00', $customer->credit_limit);
    }

    #[Test]
    public function a_customer_can_be_deactivated_and_reactivated(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->patch(route('customers.status.update', $customer))
            ->assertSessionHasNoErrors();

        $this->assertFalse($customer->fresh()->is_active);

        $this->actingAs($user)->patch(route('customers.status.update', $customer));

        $this->assertTrue($customer->fresh()->is_active);

        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    #[Test]
    public function a_negative_credit_limit_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            route('customers.store'),
            $this->validPayload(['credit_limit' => '-1.00'])
        );

        $response->assertSessionHasErrors('credit_limit');
        $this->assertDatabaseMissing('customers', ['code' => 'CUS-001']);
    }

    #[Test]
    public function negative_credit_days_are_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(
            route('customers.store'),
            $this->validPayload(['credit_days' => -5])
        );

        $response->assertSessionHasErrors('credit_days');
        $this->assertDatabaseMissing('customers', ['code' => 'CUS-001']);
    }

    #[Test]
    public function the_customer_name_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('customers.store'), $this->validPayload(['name' => '']))
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function the_credit_limit_keeps_two_decimal_places(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('customers.store'), $this->validPayload(['credit_limit' => '1234.5']))
            ->assertSessionHasNoErrors();

        // decimal:2 cast, never a float — the value must round-trip exactly.
        $this->assertSame('1234.50', Customer::where('code', 'CUS-001')->value('credit_limit'));
    }

    #[Test]
    public function there_is_no_destroy_route_for_customers(): void
    {
        $this->assertFalse(
            app('router')->getRoutes()->hasNamedRoute('customers.destroy'),
            'Customers must be deactivated, never deleted.'
        );
    }

    #[Test]
    public function the_customers_table_has_no_balance_columns(): void
    {
        // credit_limit is an agreed term. Outstanding receivables are derived from posted
        // transactions in the finance module and must never be stored here.
        foreach (['balance', 'current_balance', 'outstanding', 'receivable'] as $column) {
            $this->assertFalse(
                Schema::hasColumn('customers', $column),
                "The customers table must not have a [{$column}] column."
            );
        }
    }
}
