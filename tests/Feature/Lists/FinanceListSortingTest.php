<?php

namespace Tests\Feature\Lists;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every sort column the finance lists offer runs, both ways, together with a period and "All" —
 * a column offered on screen must never fail in the database.
 */
class FinanceListSortingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function lists(): array
    {
        return [
            'sales invoices' => ['sales-invoices.index', ['number', 'customer', 'date', 'due', 'status', 'total']],
            'customer receipts' => ['customer-receipts.index', ['number', 'customer', 'date', 'account', 'reference', 'status', 'amount']],
            'credit notes' => ['credit-notes.index', ['number', 'date', 'customer', 'invoice', 'kind', 'amount', 'status']],
            'supplier invoices' => ['supplier-invoices.index', ['number', 'supplier_number', 'supplier', 'date', 'due', 'status', 'total']],
            'supplier payments' => ['supplier-payments.index', ['number', 'supplier', 'date', 'account', 'reference', 'status', 'amount']],
            'customer advances' => ['customer-advances.index', ['number', 'date', 'party', 'amount', 'status']],
            'supplier advances' => ['supplier-advances.index', ['number', 'date', 'party', 'amount', 'status']],
            'expense vouchers' => ['expense-vouchers.index', ['number', 'date', 'payee', 'account', 'status', 'amount']],
            'fund transfers' => ['fund-transfers.index', ['number', 'date', 'from', 'to', 'status', 'amount']],
            'journals' => ['journals.index', ['number', 'date', 'description', 'source', 'status', 'debit', 'credit']],
        ];
    }

    /**
     * @param  list<string>  $sorts
     */
    #[Test]
    #[DataProvider('lists')]
    public function every_offered_sort_runs(string $route, array $sorts): void
    {
        $admin = User::factory()->admin()->create();

        foreach ($sorts as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $this->actingAs($admin)
                    ->get(route($route, ['sort' => $sort, 'direction' => $direction, 'per_page' => 'all', 'from' => '2026-09-01', 'to' => '2026-09-30', 'search' => 'x']))
                    ->assertOk()
                    ->assertInertia(fn ($page) => $page->where('filters.sort', $sort)->where('filters.direction', $direction)->where('filters.from', '2026-09-01'));
            }
        }
    }
}
