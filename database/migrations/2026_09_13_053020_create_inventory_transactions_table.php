<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventory transaction header.
     *
     * Posted transactions are IMMUTABLE — never edited, never deleted. Corrections are made
     * by posting a reversal, which is a new transaction pointing back at the original via
     * reversal_of_id. See docs/INVENTORY_RULES.md §9 and §12.
     */
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 50)->unique();
            $table->string('transaction_type', 50);
            $table->dateTime('transaction_date');

            // Origin document. Nullable because the engine can be exercised directly.
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // Reversal links. A transaction may be reversed at most once, which is enforced
            // by the unique index on reversal_of_id.
            $table->foreignId('reversal_of_id')
                ->nullable()
                ->constrained('inventory_transactions')
                ->restrictOnDelete();
            $table->foreignId('reversed_by_id')
                ->nullable()
                ->constrained('inventory_transactions')
                ->restrictOnDelete();

            $table->text('notes')->nullable();

            $table->foreignId('posted_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->dateTime('posted_at');

            $table->timestamps();

            // One transaction can only ever be reversed once.
            $table->unique('reversal_of_id');

            $table->index('transaction_type');
            $table->index('transaction_date');
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
