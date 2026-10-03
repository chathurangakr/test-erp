<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How money was paid or received — cash, cheque or bank transfer — and a cheque's details.
 *
 * "Credit" is deliberately NOT a method: buying or selling on credit simply leaves an invoice
 * outstanding until a payment or receipt settles it. Cheque details are recorded only; there
 * is no cheque status or clearing account (cheque lifecycle is TBD). The method is NULL on
 * documents created before it existed — nothing is invented for them.
 *
 * See docs/ACCOUNTING_RULES.md §29.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['supplier_payments', 'customer_receipts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('payment_method', 20)->nullable()->after('amount');
                $table->string('cheque_number', 50)->nullable()->after('payment_method');
                $table->date('cheque_date')->nullable()->after('cheque_number');
                $table->string('cheque_bank', 150)->nullable()->after('cheque_date');
            });
        }
    }

    public function down(): void
    {
        foreach (['supplier_payments', 'customer_receipts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(['payment_method', 'cheque_number', 'cheque_date', 'cheque_bank']);
            });
        }
    }
};
