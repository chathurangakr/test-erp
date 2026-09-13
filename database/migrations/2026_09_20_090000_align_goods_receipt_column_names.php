<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Align two goods receipt column names with the confirmed specification.
     *
     *   reversal_transaction_id -> reversal_inventory_transaction_id
     *   base_material_cost      -> material_cost
     *
     * A forward rename rather than a drop-and-recreate, so any existing rows keep their
     * values. Each rename is guarded so the migration is safe to run against a database
     * that already has the final names.
     */
    public function up(): void
    {
        if (Schema::hasColumn('goods_receipts', 'reversal_transaction_id')) {
            Schema::table('goods_receipts', function (Blueprint $table) {
                $table->renameColumn('reversal_transaction_id', 'reversal_inventory_transaction_id');
            });
        }

        if (Schema::hasColumn('goods_receipt_lines', 'base_material_cost')) {
            Schema::table('goods_receipt_lines', function (Blueprint $table) {
                $table->renameColumn('base_material_cost', 'material_cost');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('goods_receipts', 'reversal_inventory_transaction_id')) {
            Schema::table('goods_receipts', function (Blueprint $table) {
                $table->renameColumn('reversal_inventory_transaction_id', 'reversal_transaction_id');
            });
        }

        if (Schema::hasColumn('goods_receipt_lines', 'material_cost')) {
            Schema::table('goods_receipt_lines', function (Blueprint $table) {
                $table->renameColumn('material_cost', 'base_material_cost');
            });
        }
    }
};
