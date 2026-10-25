<?php

namespace Tests\Feature\Lists;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every list shares the same controls (App\Support\ListQuery): rows per page — 25, 50, 100 or All
 * (the whole history unless a period is chosen) — sorting by a column the screen allows, and a
 * From / To period on the list's date. Shown on the deliveries list.
 */
class ListControlsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $customer = Customer::factory()->create();
        $warehouse = Warehouse::factory()->create();

        // 60 deliveries, one a day from 1 July 2026.
        foreach (range(0, 59) as $day) {
            Delivery::factory()->create([
                'customer_id' => $customer->id,
                'warehouse_id' => $warehouse->id,
                'delivery_date' => Carbon::parse('2026-07-01')->addDays($day)->toDateString(),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $query
     * @return list<string>
     */
    private function dates(array $query): array
    {
        $dates = [];

        $this->actingAs($this->admin)->get(route('deliveries.index', $query))->assertOk()
            ->assertInertia(function ($page) use (&$dates): void {
                $dates = array_column($page->toArray()['props']['deliveries']['data'], 'delivery_date');
            });

        return $dates;
    }

    #[Test]
    public function rows_per_page_and_all(): void
    {
        $this->assertCount(25, $this->dates([]));
        $this->assertCount(50, $this->dates(['per_page' => '50']));
        $this->assertCount(60, $this->dates(['per_page' => 'all']), 'All is the whole history when no period is chosen.');
        $this->assertCount(25, $this->dates(['per_page' => '7']), 'Only the offered sizes are accepted.');
    }

    #[Test]
    public function all_within_a_period_shows_only_that_period(): void
    {
        $dates = $this->dates(['per_page' => 'all', 'from' => '2026-08-01', 'to' => '2026-08-10']);

        $this->assertCount(10, $dates);
        $this->assertSame('2026-08-10', $dates[0], 'Newest first by default.');
        $this->assertSame('2026-08-01', end($dates));
        $this->assertCount(60, $this->dates(['per_page' => 'all', 'from' => 'not-a-date']), 'A bad date is ignored.');
    }

    #[Test]
    public function sorting_follows_the_chosen_column_and_ignores_others(): void
    {
        $this->assertSame('2026-07-01', $this->dates(['sort' => 'date', 'direction' => 'asc'])[0]);
        $this->assertSame('2026-08-29', $this->dates(['sort' => 'date', 'direction' => 'desc'])[0]);
        $this->assertSame('2026-08-29', $this->dates(['sort' => 'password', 'direction' => 'asc'])[0], 'A column the screen does not offer falls back to the usual order.');

        $this->actingAs($this->admin)->get(route('deliveries.index', ['sort' => 'sales', 'direction' => 'asc', 'per_page' => 'all']))->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.sort', 'sales')->where('filters.direction', 'asc')->where('filters.per_page', 'all'));
    }
}
