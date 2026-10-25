<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer and supplier advances (2026-10-25). Money received from a customer, or paid to a
 * supplier, BEFORE the invoice exists:
 *
 *   customer advance       Dr cash/bank        / Cr customer advances (2130, liability)
 *   applied to an invoice  Dr customer advances / Cr trade receivables
 *   supplier advance       Dr supplier advances (1230, asset) / Cr cash/bank
 *   applied to an invoice  Dr trade payables    / Cr supplier advances
 *
 * What is left of an advance is derived (amount − standing applications), never stored. Each
 * document is written in one step and corrected by reversal. See docs/ACCOUNTING_RULES.md §34.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->foreignId('customer_advances_account_id')->nullable()->after('sales_discount_account_id')
                ->constrained('accounts')->restrictOnDelete();
            $table->foreignId('supplier_advances_account_id')->nullable()->after('customer_advances_account_id')
                ->constrained('accounts')->restrictOnDelete();
        });

        foreach (['customer' => 'customers', 'supplier' => 'suppliers'] as $party => $parties) {
            Schema::create("{$party}_advances", function (Blueprint $table) use ($party, $parties) {
                $table->id();
                $table->string('advance_number', 30)->unique();
                $table->foreignId("{$party}_id")->constrained($parties)->restrictOnDelete();
                $table->date('advance_date');
                $table->decimal('amount', 20, 6);
                $table->foreignId('cash_bank_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();
                $table->string('payment_method', 20);
                $table->string('cheque_number', 50)->nullable();
                $table->date('cheque_date')->nullable();
                $table->string('cheque_bank', 150)->nullable();
                $table->string('reference', 100)->nullable();
                $table->string('note', 500)->nullable();
                $table->string('status', 10);
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();

                $table->index(["{$party}_id", 'status']);
            });

            Schema::create("{$party}_advance_applications", function (Blueprint $table) use ($party) {
                $table->id();
                $table->foreignId("{$party}_advance_id")->constrained("{$party}_advances")->restrictOnDelete();
                $table->foreignId($party === 'customer' ? 'sales_invoice_id' : 'supplier_invoice_id')
                    ->constrained($party === 'customer' ? 'sales_invoices' : 'supplier_invoices')->restrictOnDelete();
                $table->date('application_date');
                $table->decimal('amount', 20, 6);
                $table->string('status', 10);
                $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();

                $table->index(["{$party}_advance_id", 'status'], "{$party}_adv_app_advance_status_index");
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_advance_applications');
        Schema::dropIfExists('supplier_advances');
        Schema::dropIfExists('customer_advance_applications');
        Schema::dropIfExists('customer_advances');

        Schema::table('accounting_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_advances_account_id');
            $table->dropConstrainedForeignId('customer_advances_account_id');
        });
    }
};
