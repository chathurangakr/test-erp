<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal entry lines — the double-entry ledger itself.
 *
 * Debit and credit are separate columns rather than one signed amount. A line is one or the
 * other, never both and never neither, and both are stored POSITIVE. That makes an
 * unbalanced entry a sum that does not match rather than a sign convention someone has to
 * remember, and it is what a trial balance reads directly.
 *
 * `decimal(20,6)` matches the inventory ledger's cost scale deliberately: when Finance later
 * posts inventory values, the authoritative `total_cost` must transfer without rounding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('description', 500)->nullable();
            $table->decimal('debit', 20, 6)->default(0);
            $table->decimal('credit', 20, 6)->default(0);
            $table->timestamps();

            $table->index(['account_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_lines');
    }
};
