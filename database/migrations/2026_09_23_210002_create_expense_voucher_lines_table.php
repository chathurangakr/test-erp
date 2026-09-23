<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One expense charged on a voucher.
 *
 * `expense_account_id` is validated against ordinary EXPENSE accounts at posting time — a
 * request must never be able to name an inventory, receivable, payable, GRNI, revenue or
 * equity account here, which would move value with no document behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_voucher_lines', function (Blueprint $table) {
            $table->id();

            $table->foreignId('expense_voucher_id')->constrained('expense_vouchers')->cascadeOnDelete();
            $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('description', 255)->nullable();

            // Money, so decimal — never float. Matches the accounting scale exactly.
            $table->decimal('amount', 20, 6);

            $table->timestamps();

            $table->index(['expense_voucher_id', 'expense_account_id'], 'evl_voucher_account_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_voucher_lines');
    }
};
