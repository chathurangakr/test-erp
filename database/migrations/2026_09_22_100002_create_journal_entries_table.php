<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal entry headers.
 *
 * `source_type` / `source_id` are nullable because Finance Phase 1 only creates manual
 * journals. They exist now so that when purchasing, sales and production start generating
 * entries, each one can name the document it came from without a schema change.
 *
 * The reversal pair mirrors the inventory ledger: `reversal_of_id` points at the original
 * and the original's `reversed_by_id` points back, with a unique index making a second
 * reversal impossible at the database level rather than only in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('journal_number', 50)->unique();
            $table->date('journal_date')->index();
            $table->foreignId('fiscal_period_id')->constrained('fiscal_periods')->restrictOnDelete();

            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('description', 500);
            $table->string('status', 20)->index();

            $table->foreignId('reversal_of_id')->nullable()->unique()
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversed_by_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();

            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
