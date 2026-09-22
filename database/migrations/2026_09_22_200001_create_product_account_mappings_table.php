<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which accounts a product's stock movements touch.
 *
 * This table exists so that no account code ever appears in a posting action. Purchasing,
 * sales and production ask the mapping which account to use; a factory that renumbers its
 * chart changes data, not software.
 *
 * A product with no row here has no accounting meaning yet. Once the cutover date is set, a
 * movement of such a product is REFUSED rather than guessed at — a wrong account written
 * permanently into the books is worse than a failed posting.
 *
 * `revenue_account_id` is nullable and unused in this phase. It is configured now so that
 * sales invoicing, when it arrives, needs no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();

            $table->foreignId('inventory_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('cogs_account_id')->constrained('accounts')->restrictOnDelete();
            // Reserved for the later sales-invoice phase; no revenue journal is created yet.
            $table->foreignId('revenue_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_account_mappings');
    }
};
