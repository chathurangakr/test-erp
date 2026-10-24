<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed assets (2026-10-25): vehicles, machines and other things the factory owns. The owner
 * chose purchase and sale price only — no depreciation — and a maintenance log per asset.
 * An asset bought on a loan opens that loan too. See docs/FIXED_ASSETS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->foreignId('fixed_assets_account_id')->nullable()->after('opening_balance_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('asset_gain_account_id')->nullable()->after('fixed_assets_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('asset_loss_account_id')->nullable()->after('asset_gain_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('maintenance_account_id')->nullable()->after('asset_loss_account_id')->constrained('accounts')->restrictOnDelete();
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_number', 30)->unique();
            $table->string('name', 150);
            $table->string('category', 30);
            $table->text('description')->nullable();
            $table->date('purchase_date');
            $table->decimal('purchase_price', 20, 6);
            // CASH_BANK: paid in full; LOAN: a down payment (maybe none) and a loan for the rest;
            // EXISTING: owned before the system, brought in at what it cost.
            $table->string('funding', 20);
            $table->decimal('down_payment', 20, 6)->default(0);
            $table->foreignId('cash_bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('loan_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            // The sale (or scrapping, at 0), once; undone by reversing the sale.
            $table->date('sold_date')->nullable();
            $table->decimal('sale_price', 20, 6)->nullable();
            $table->string('buyer', 150)->nullable();
            $table->foreignId('sale_cash_bank_account_id')->nullable()->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->string('sale_payment_method', 20)->nullable();
            $table->foreignId('sale_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('loans', function (Blueprint $table) {
            // An asset bought on this loan: its purchase journal is the loan's journal.
            $table->foreignId('fixed_asset_id')->nullable()->after('cash_bank_account_id')->constrained()->restrictOnDelete();
        });

        Schema::create('asset_maintenances', function (Blueprint $table) {
            $table->id();
            $table->string('maintenance_number', 30)->unique();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->date('maintenance_date');
            $table->string('work_done', 255);
            $table->string('done_by', 150)->nullable();
            $table->decimal('cost', 20, 6);
            $table->foreignId('cash_bank_account_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('payment_method', 20)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('status', 20);
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['fixed_asset_id', 'maintenance_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenances');

        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fixed_asset_id');
        });

        Schema::dropIfExists('fixed_assets');

        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('maintenance_account_id');
            $table->dropConstrainedForeignId('asset_loss_account_id');
            $table->dropConstrainedForeignId('asset_gain_account_id');
            $table->dropConstrainedForeignId('fixed_assets_account_id');
        });
    }
};
