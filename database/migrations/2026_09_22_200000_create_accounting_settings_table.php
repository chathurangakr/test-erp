<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounting configuration — a single row.
 *
 * The important column is `operational_accounting_start_date`, the **cutover**. It is
 * nullable and is never invented: until an accountant sets it, operational documents post
 * stock exactly as before and generate no journals at all. That is what lets accounting be
 * switched on against a system that already holds months of operational history, without
 * backfilling or reinterpreting any of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_settings', function (Blueprint $table) {
            $table->id();

            // NULL means "operational accounting is not switched on yet".
            $table->date('operational_accounting_start_date')->nullable();

            // Goods Received Not Invoiced: the liability a receipt creates before the
            // supplier's invoice is recorded.
            $table->foreignId('grni_account_id')->nullable()->constrained('accounts')->restrictOnDelete();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_settings');
    }
};
