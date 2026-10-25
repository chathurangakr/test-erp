<?php

namespace Tests\Feature\Lists;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The master, inventory and payroll lists offer every sort they show, in both directions, with
 * All rows and a period where the list has a date — and each one runs.
 */
class MasterInventoryPayrollListsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: list<string>, 2: bool}>
     */
    public static function lists(): array
    {
        return [
            'customers' => ['customers.index', ['code', 'name', 'contact', 'phone', 'city', 'credit_limit', 'status'], false],
            'suppliers' => ['suppliers.index', ['code', 'name', 'contact', 'phone', 'city', 'terms', 'status'], false],
            'products' => ['products.index', ['code', 'name', 'primary_uom', 'secondary_uom', 'status'], false],
            'warehouses' => ['warehouses.index', ['code', 'name', 'default', 'status'], false],
            'accounts' => ['accounts.index', ['code', 'name', 'type', 'control', 'status'], false],
            'users' => ['users.index', ['name', 'email', 'status'], false],
            'employees' => ['employees.index', ['code', 'name', 'phone', 'daily_rate', 'status'], false],
            'damages' => ['stock-damages.index', ['number', 'date', 'warehouse', 'reason', 'value', 'status'], true],
            'lots' => ['inventory.lots.index', ['lot', 'product', 'manufactured', 'received', 'status'], true],
            'ledger' => ['inventory.ledger.index', ['date', 'reference', 'type', 'product', 'warehouse', 'lot', 'unit_cost', 'total_cost'], true],
            'salaries' => ['payrolls.index', ['number', 'period', 'pay_date', 'employees', 'gross_pay', 'advances', 'net_pay', 'status'], true],
            'salary advances' => ['salary-advances.index', ['number', 'date', 'employee', 'amount', 'status'], true],
        ];
    }

    /**
     * @param  list<string>  $sorts
     */
    #[Test]
    #[DataProvider('lists')]
    public function every_sort_runs_and_is_echoed_back(string $route, array $sorts, bool $hasPeriod): void
    {
        $admin = User::factory()->admin()->create();
        Customer::factory()->create();
        Supplier::factory()->create();
        Product::factory()->create();
        Warehouse::factory()->create();
        Employee::factory()->create();

        foreach ($sorts as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $query = ['sort' => $sort, 'direction' => $direction, 'per_page' => 'all'] + ($hasPeriod ? ['from' => '2026-09-01', 'to' => '2026-09-30'] : []);

                $this->actingAs($admin)->get(route($route, $query))->assertOk()
                    ->assertInertia(fn ($page) => $page
                        ->where('filters.sort', $sort)
                        ->where('filters.direction', $direction)
                        ->where('filters.per_page', 'all')
                        ->where('filters.from', $hasPeriod ? '2026-09-01' : null));
            }
        }
    }
}
