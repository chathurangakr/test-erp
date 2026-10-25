<?php

namespace App\Models;

use App\Enums\AdvanceStatus;
use App\Enums\PaymentMethod;
use Database\Factories\SupplierAdvanceRefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Part of a supplier advance returned to us by the supplier: `Dr cash or bank / Cr supplier advances`. Immutable once written except for the journal link and the
 * reversal trail; never deleted. See docs/ACCOUNTING_RULES.md §34.
 *
 * @property Carbon $refund_date
 * @property string $amount
 * @property AdvanceStatus $status
 */
class SupplierAdvanceRefund extends Model
{
    /** @use HasFactory<SupplierAdvanceRefundFactory> */
    use HasFactory;

    private const AUDIT = ['journal_entry_id', 'status', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'updated_at'];

    protected $fillable = [
        'supplier_advance_id', 'refund_date', 'amount', 'cash_bank_account_id', 'payment_method', 'reference', 'status',
        'journal_entry_id', 'reversal_journal_entry_id', 'created_by', 'posted_at', 'reversed_by', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'refund_date' => 'date',
            'amount' => 'decimal:6',
            'payment_method' => PaymentMethod::class,
            'status' => AdvanceStatus::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $refund): void {
            if (array_diff(array_keys($refund->getDirty()), self::AUDIT) !== []) {
                throw new LogicException('A posted advance refund cannot be edited. Reverse it instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('An advance refund is never deleted.'));
    }

    /** @return BelongsTo<SupplierAdvance, $this> */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(SupplierAdvance::class, 'supplier_advance_id');
    }

    /** @return BelongsTo<CashBankAccount, $this> */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
