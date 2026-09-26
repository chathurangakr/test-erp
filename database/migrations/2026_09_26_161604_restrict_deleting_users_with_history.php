<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The database refuses to delete a user who appears in business history.
 *
 * The finance tables were created with `nullOnDelete()` on their audit columns, so deleting
 * a user would have silently blanked "who posted this" on every journal, invoice, payment,
 * voucher and transfer they touched — audit history lost without a trace. Purchasing, sales,
 * inventory and production already used `restrictOnDelete()`; this brings finance into line.
 *
 * Only the constraint's ON DELETE rule changes. No row is read or written. The application
 * refuses such a deletion first (`UserHistory`), with a readable message; this is the last
 * line of defence behind it. See docs/AUTHORIZATION_RULES.md.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const AUDIT_COLUMNS = [
        'accounting_settings' => ['updated_by'],
        'customer_receipts' => ['created_by', 'posted_by', 'reversed_by'],
        'expense_vouchers' => ['created_by', 'posted_by', 'reversed_by'],
        'fiscal_periods' => ['closed_by'],
        'fund_transfers' => ['created_by', 'posted_by', 'reversed_by'],
        'journal_entries' => ['created_by', 'posted_by', 'reversed_by_user_id'],
        'sales_invoices' => ['created_by', 'posted_by', 'reversed_by'],
        'supplier_invoices' => ['created_by', 'posted_by', 'reversed_by'],
        'supplier_payments' => ['created_by', 'posted_by', 'reversed_by'],
    ];

    public function up(): void
    {
        $this->rebuild(restrict: true);
    }

    public function down(): void
    {
        $this->rebuild(restrict: false);
    }

    private function rebuild(bool $restrict): void
    {
        foreach (self::AUDIT_COLUMNS as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns): void {
                foreach ($columns as $column) {
                    $table->dropForeign([$column]);
                }
            });

            Schema::table($tableName, function (Blueprint $table) use ($columns, $restrict): void {
                foreach ($columns as $column) {
                    $foreign = $table->foreign($column)->references('id')->on('users');
                    $restrict ? $foreign->restrictOnDelete() : $foreign->nullOnDelete();
                }
            });
        }
    }
};
