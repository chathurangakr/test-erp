<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A month's salaries, paid.
 *
 *   Dr wages                       — everything earned (gross)
 *       Cr employee advances       — advances taken back from that pay
 *       Cr the cash / bank account — what was handed over (net)
 *
 * Posted in one step: preparing a month only previews it, and posting stores one line per
 * employee with the figures actually paid. A posted run is immutable; a mistake is reversed,
 * which releases its attendance days and advance deductions. Totals are never stored — they
 * are the sum of the lines.
 *
 * See docs/PAYROLL.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('payroll_number', 50)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');
            $table->foreignId('fiscal_period_id')->nullable()->constrained('fiscal_periods')->nullOnDelete();
            $table->foreignId('cash_bank_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->string('payment_method', 30);
            $table->string('reference', 100)->nullable();
            $table->string('status', 30);

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['period_start', 'status']);
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->unsignedSmallInteger('full_days');
            $table->unsignedSmallInteger('half_days');
            $table->unsignedSmallInteger('leave_days');
            $table->decimal('gross_pay', 20, 2);
            $table->decimal('advances_deducted', 20, 2);
            $table->decimal('net_pay', 20, 2);
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
    }
};
