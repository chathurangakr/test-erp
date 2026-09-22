<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiscal periods.
 *
 * A journal may only be posted into an OPEN period whose date range contains its date, so
 * the period is the gate that stops a reported month being changed after the fact.
 *
 * Deliberately NOT tied to a calendar year: the table stores arbitrary start and end dates,
 * because a factory's reporting year is a business decision, not a property of the calendar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->index();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
    }
};
