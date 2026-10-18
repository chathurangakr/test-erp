<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The people the factory pays by the day.
 *
 * A master table like suppliers and customers: codes are unique and uppercase, nobody is
 * deleted (an employee with history is deactivated), and there is no balance column — what an
 * employee is owed or owes is always derived from attendance, advances and paid salaries.
 *
 * `daily_rate` is the rate for days recorded FROM NOW ON. Each attendance day keeps the rate
 * it was recorded at, so changing it never alters a day already worked.
 *
 * See docs/PAYROLL.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->string('nic', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->decimal('daily_rate', 20, 2);
            $table->date('joined_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
