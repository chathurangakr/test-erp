<?php

namespace Tests\Feature\Masters;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * New suppliers and customers are numbered automatically, continuing from the highest number
 * already used — whatever padding older, hand-typed codes have.
 */
class AutomaticCodeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_new_supplier_gets_the_next_code_after_the_highest_in_use(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // Codes typed by hand before this existed, with mixed padding.
        Supplier::factory()->create(['code' => 'SUP-009']);
        Supplier::factory()->create(['code' => 'SUP-0028']);
        Supplier::factory()->create(['code' => 'SUP-27']);
        Supplier::factory()->create(['code' => 'OLD-500']);

        $this->get(route('suppliers.create'))->assertInertia(fn ($page) => $page->where('nextCode', 'SUP-029'));

        $this->post(route('suppliers.store'), ['name' => 'New Grower', 'payment_terms_days' => 0])->assertSessionHasNoErrors();
        $this->post(route('suppliers.store'), ['name' => 'Second Grower', 'payment_terms_days' => 0])->assertSessionHasNoErrors();

        $this->assertSame('SUP-029', Supplier::query()->where('name', 'New Grower')->value('code'));
        $this->assertSame('SUP-030', Supplier::query()->where('name', 'Second Grower')->value('code'));
    }

    #[Test]
    public function a_new_customer_starts_at_one_and_counts_up(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get(route('customers.create'))->assertInertia(fn ($page) => $page->where('nextCode', 'CUS-001'));

        foreach (['First Buyer', 'Second Buyer'] as $name) {
            $this->post(route('customers.store'), ['name' => $name, 'credit_limit' => '0', 'credit_days' => 0])->assertSessionHasNoErrors();
        }

        $this->assertSame(['CUS-001', 'CUS-002'], Customer::query()->orderBy('id')->pluck('code')->all());
    }

    #[Test]
    public function the_api_numbers_a_supplier_sent_without_a_code(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['mobile-api']);

        $this->postJson(route('api.v1.suppliers.store'), ['name' => 'Phone Grower', 'payment_terms_days' => 0])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SUP-001');
    }
}
