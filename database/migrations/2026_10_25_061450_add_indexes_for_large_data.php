<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for very large data (2026-10-25), measured on five-plus years of synthetic history
 * (3 million journal lines, 1.6 million stock lines, 200,000 deliveries). Adds indexes only — no
 * data changes.
 *
 * - Lists: every list filters by a From / To period on its date and sorts by it by default
 *   (App\Support\ListQuery); these lists had no index on that date.
 * - Ledgers: balances, statements and stock are summed from journal and stock lines. "Covering"
 *   indexes hold the amounts too, so a sum reads only the index, never the rows — a product's
 *   weighted average (on every posting) went from 64 ms to 16 ms and the trial balance from 3.9 s
 *   to 2.5 s. The older indexes they extend are dropped, so posting still writes as few indexes.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => the date column its list filters and sorts on */
    private const DATES = [
        'customer_advances' => ['advance_date'],
        'supplier_advances' => ['advance_date'],
        'expense_vouchers' => ['voucher_date'],
        'fund_transfers' => ['transfer_date'],
        'fixed_assets' => ['purchase_date'],
        'loans' => ['start_date'],
        'payroll_runs' => ['pay_date'],
        'salary_advances' => ['advance_date'],
        'sales_credit_notes' => ['credit_date'],
        'inventory_lots' => ['received_at'],
    ];

    /**
     * New covering indexes, and the narrower ones each makes redundant.
     *
     * @var list<array{0: string, 1: string, 2: list<string>, 3: list<string>}> table, name, columns, replaces
     */
    private const LEDGER = [
        ['journal_entries', 'je_date_status_index', ['journal_date', 'status'], ['journal_entries_journal_date_index']],
        ['journal_entry_lines', 'jel_account_entry_amounts_index', ['account_id', 'journal_entry_id', 'debit', 'credit'], ['journal_entry_lines_account_id_journal_entry_id_index']],
        ['journal_entry_lines', 'jel_entry_account_amounts_index', ['journal_entry_id', 'account_id', 'debit', 'credit'], []],
        ['inventory_transaction_lines', 'itl_product_amounts_index', ['product_id', 'direction', 'quantity', 'total_cost', 'secondary_quantity'], ['inventory_transaction_lines_product_id_direction_index']],
        ['inventory_transaction_lines', 'itl_product_place_amounts_index', ['product_id', 'warehouse_id', 'lot_id', 'direction', 'quantity', 'total_cost', 'secondary_quantity'], ['inventory_transaction_lines_product_id_warehouse_id_index', 'inventory_transaction_lines_product_id_warehouse_id_lot_id_index']],
    ];

    public function up(): void
    {
        foreach (self::DATES as $table => $columns) {
            if (! Schema::hasIndex($table, $columns)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns));
            }
        }

        foreach (self::LEDGER as [$table, $name, $columns, $replaces]) {
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
            }

            foreach ($replaces as $old) {
                if (Schema::hasIndex($table, $old)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($old));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::LEDGER) as [$table, $name, $columns, $replaces]) {
            foreach ($replaces as $old) {
                if (! Schema::hasIndex($table, $old)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($this->columnsOf($old), $old));
                }
            }

            if (Schema::hasIndex($table, $name)) {
                // MariaDB may have retired the index it made for a foreign key once this one could
                // serve it; give the key a plain index back before this one goes.
                $leading = $columns[0];
                $others = collect(Schema::getIndexes($table))->reject(fn (array $index): bool => $index['name'] === $name);

                if (! $others->contains(fn (array $index): bool => ($index['columns'][0] ?? null) === $leading)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index([$leading]));
                }

                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }

        foreach (self::DATES as $table => $columns) {
            if (Schema::hasIndex($table, $columns)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($columns));
            }
        }
    }

    /** @return list<string> the columns an index this migration dropped was built on */
    private function columnsOf(string $index): array
    {
        return match ($index) {
            'journal_entries_journal_date_index' => ['journal_date'],
            'journal_entry_lines_account_id_journal_entry_id_index' => ['account_id', 'journal_entry_id'],
            'inventory_transaction_lines_product_id_direction_index' => ['product_id', 'direction'],
            'inventory_transaction_lines_product_id_warehouse_id_index' => ['product_id', 'warehouse_id'],
            'inventory_transaction_lines_product_id_warehouse_id_lot_id_index' => ['product_id', 'warehouse_id', 'lot_id'],
        };
    }
};
