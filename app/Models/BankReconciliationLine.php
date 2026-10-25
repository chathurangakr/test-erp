<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One journal line a bank reconciliation found on the statement. Written with its
 * reconciliation and never changed.
 */
class BankReconciliationLine extends Model
{
    protected $fillable = ['bank_reconciliation_id', 'journal_entry_line_id'];

    /** @return BelongsTo<BankReconciliation, $this> */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'bank_reconciliation_id');
    }

    /** @return BelongsTo<JournalEntryLine, $this> */
    public function journalLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class, 'journal_entry_line_id');
    }
}
