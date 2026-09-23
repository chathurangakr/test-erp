<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which general-ledger accounts the factory actually treats as cash or a bank account.
 *
 * This is **configuration, not a ledger**. It carries no balance and never will: what is in
 * the till or the bank is derived from posted journal lines, exactly as stock is derived
 * from the inventory ledger. The row exists to say "this GL account is a real cash or bank
 * account, and here is what to call it", so every module that moves money — supplier
 * payments, customer receipts, expense vouchers, fund transfers — asks one question of one
 * table instead of each deciding for itself what an acceptable asset account looks like.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_bank_accounts', function (Blueprint $table) {
            $table->id();

            // One configuration per GL account: two names for the same money would be two
            // versions of the truth.
            $table->foreignId('account_id')->unique()->constrained('accounts')->restrictOnDelete();

            $table->string('account_kind', 20);
            $table->string('display_name', 150);
            $table->string('bank_name', 150)->nullable();
            $table->string('branch_name', 150)->nullable();
            $table->string('account_number', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['is_active', 'account_kind'], 'cba_active_kind_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_bank_accounts');
    }
};
