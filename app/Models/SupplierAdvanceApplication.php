<?php

namespace App\Models;

use App\Enums\AdvanceStatus;
use Database\Factories\SupplierAdvanceApplicationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Part of a supplier advance set against one of the supplier's invoices — `Dr trade payables / Cr supplier advances`.
 * Immutable once written except for the journal link and the reversal trail; never deleted.
 * See docs/ACCOUNTING_RULES.md §34.
 *
 * @property Carbon $application_date
 * @property string $amount
 * @property AdvanceStatus $status
 */
class SupplierAdvanceApplication extends Model
{
    /** @use HasFactory<SupplierAdvanceApplicationFactory> */
    use HasFactory;

    private const AUDIT = ['journal_entry_id', 'status', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'updated_at'];

    protected $fillable = [
        'supplier_advance_id', 'supplier_invoice_id', 'application_date', 'amount', 'status', 'journal_entry_id',
        'reversal_journal_entry_id', 'created_by', 'posted_at', 'reversed_by', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'application_date' => 'date',
            'amount' => 'decimal:6',
            'status' => AdvanceStatus::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $application): void {
            if (array_diff(array_keys($application->getDirty()), self::AUDIT) !== []) {
                throw new LogicException('A posted advance application cannot be edited. Reverse it instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('An advance application is never deleted.'));
    }

    /** @return BelongsTo<SupplierAdvance, $this> */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(SupplierAdvance::class, 'supplier_advance_id');
    }

    /** @return BelongsTo<SupplierInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
