<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money given to an employee before payday.
 *
 *   Dr employee advances (an asset: the employee owes it back)
 *       Cr the cash / bank account
 *
 * Recorded and paid in one step. It is taken back in full from the next salary paid for a
 * month ending on or after its date; whatever a salary cannot cover carries to the month after.
 * What an employee still owes is derived (advances − deductions), never stored.
 *
 * See docs/PAYROLL.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->string('advance_number', 50)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('advance_date');
            $table->decimal('amount', 20, 2);
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();
            $table->foreignId('cash_bank_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->string('payment_method', 30);
            $table->string('reference', 100)->nullable();
            $table->string('note', 255)->nullable();
            $table->string('status', 30);

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status', 'advance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_advances');
    }
};
