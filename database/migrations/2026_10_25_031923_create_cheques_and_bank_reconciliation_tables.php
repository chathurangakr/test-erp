<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cheque lifecycle and bank reconciliation (2026-10-25). Neither changes how money is journalled:
 *
 * - `cheque_events` — what happened to a cheque recorded on a customer receipt, supplier payment,
 *   or customer / supplier advance (the document, by source_type + source_id): CLEARED on a day, or
 *   BOUNCED (the document is reversed in the same step). A cheque with no standing event is waiting.
 * - `bank_reconciliations` + `bank_reconciliation_lines` — a bank statement agreed with the books:
 *   the journal lines on that bank account the statement shows, ticked once each.
 *
 * Nothing is deleted: an event or a reconciliation is undone by marking it reversed.
 * See docs/ACCOUNTING_RULES.md §35-§36.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cheque_events', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            // CLEARED or BOUNCED.
            $table->string('event', 10);
            $table->date('event_date');
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });

        Schema::create('bank_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_bank_account_id')->constrained('cash_bank_accounts')->restrictOnDelete();
            $table->date('statement_date');
            $table->decimal('statement_balance', 20, 6);
            $table->string('status', 10);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['cash_bank_account_id', 'status', 'statement_date'], 'bank_recon_account_status_date_index');
        });

        Schema::create('bank_reconciliation_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_reconciliation_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_line_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['bank_reconciliation_id', 'journal_entry_line_id'], 'bank_recon_line_unique');
            $table->index('journal_entry_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_lines');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('cheque_events');
    }
};
