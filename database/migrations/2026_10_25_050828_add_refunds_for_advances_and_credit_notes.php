<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money back (2026-10-25):
 *
 * - `customer_advance_refunds` — unused customer advance given back: Dr customer advances / Cr cash or bank.
 * - `supplier_advance_refunds` — unused supplier advance returned to us: Dr cash or bank / Cr supplier advances.
 * - `sales_credit_notes.settlement` — CREDIT (reduce what the invoice is owed, as before) or REFUND
 *   (money back from cash or bank, for an invoice already paid): Dr sales discounts / Cr cash or bank.
 *
 * Each is recorded in one step and corrected by reversal. See docs/ACCOUNTING_RULES.md §33-§34.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['customer', 'supplier'] as $party) {
            Schema::create("{$party}_advance_refunds", function (Blueprint $table) use ($party) {
                $table->id();
                $table->foreignId("{$party}_advance_id")->constrained("{$party}_advances")->restrictOnDelete();
                $table->date('refund_date');
                $table->decimal('amount', 20, 6);
                $table->foreignId('cash_bank_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();
                $table->string('payment_method', 20);
                $table->string('reference', 100)->nullable();
                $table->string('status', 10);
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();

                $table->index(["{$party}_advance_id", 'status'], "{$party}_adv_refund_advance_status_index");
            });
        }

        Schema::table('sales_credit_notes', function (Blueprint $table) {
            $table->string('settlement', 10)->default('CREDIT')->after('kind');
            $table->foreignId('cash_bank_account_id')->nullable()->after('settlement')->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->string('payment_method', 20)->nullable()->after('cash_bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_credit_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_bank_account_id');
            $table->dropColumn(['settlement', 'payment_method']);
        });
        Schema::dropIfExists('supplier_advance_refunds');
        Schema::dropIfExists('customer_advance_refunds');
    }
};
