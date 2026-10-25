<?php

namespace Tests\Feature\Lists;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every column the sales, purchasing and production lists offer to sort by runs, both ways, with
 * All rows and a period — so a sort that names a missing column fails here, not on a screen.
 */
class OperationsListSortingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function lists(): array
    {
        $production = ['number', 'date', 'warehouse', 'input_quantity', 'status', 'input_value', 'output_value'];

        return [
            'sales orders' => ['sales-orders.index', ['number', 'customer', 'warehouse', 'date', 'status', 'total']],
            'sales returns' => ['sales-returns.index', ['number', 'date', 'customer', 'product', 'quantity', 'credit', 'settlement', 'status']],
            'customer bags' => ['customer-bags.index', ['number', 'date', 'customer', 'kind', 'quantity', 'status']],
            'purchase orders' => ['purchase-orders.index', ['number', 'supplier', 'warehouse', 'date', 'status', 'total']],
            'goods receipts' => ['goods-receipts.index', ['number', 'supplier', 'warehouse', 'date', 'status', 'material', 'landed', 'total']],
            'dehusking' => ['dehusking.index', $production],
            'husk chipping' => ['husk-chipping.index', $production],
            'copra' => ['copra.index', $production],
        ];
    }

    /**
     * @param  list<string>  $sorts
     */
    #[Test]
    #[DataProvider('lists')]
    public function every_offered_sort_runs(string $route, array $sorts): void
    {
        $this->seedProductionRecipes();
        $admin = User::factory()->admin()->create();

        foreach ($sorts as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $this->actingAs($admin)
                    ->get(route($route, ['sort' => $sort, 'direction' => $direction, 'per_page' => 'all', 'from' => '2026-09-01', 'to' => '2026-09-30']))
                    ->assertOk()
                    ->assertInertia(fn ($page) => $page->where('filters.sort', $sort)->where('filters.direction', $direction));
            }
        }
    }
}
