<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per employee per day worked or missed: FULL_DAY, HALF_DAY or LEAVE.
 *
 * A full day earns the day's rate, a half day half of it (rounded to the cent), leave nothing.
 * A day with no row is not paid either. `daily_rate` is the rate in force when the day was
 * recorded, so a later rate change never rewrites a day already worked.
 *
 * `payroll_run_id` is set when the day is paid and cleared if that payroll is reversed. While
 * it is set, the day cannot be changed.
 *
 * See docs/PAYROLL.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->date('work_date');
            $table->string('status', 20);
            $table->decimal('daily_rate', 20, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'work_date']);
            $table->index('work_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_attendances');
    }
};
