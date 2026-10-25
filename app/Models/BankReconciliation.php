<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A bank statement agreed with the books: the journal lines on that bank account the statement
 * shows, ticked once each, so the cleared balance equals the statement balance. Never edited or
 * deleted; the latest is undone by marking it reversed, which frees its lines.
 * See docs/ACCOUNTING_RULES.md §36.
 *
 * @property Carbon $statement_date
 * @property string $statement_balance
 * @property string $status
 */
class BankReconciliation extends Model
{
    public const COMPLETED = 'COMPLETED';

    public const REVERSED = 'REVERSED';

    protected $fillable = ['cash_bank_account_id', 'statement_date', 'statement_balance', 'status', 'note', 'created_by', 'reversed_at', 'reversed_by'];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_balance' => 'decimal:6',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $reconciliation): void {
            if (array_diff(array_keys($reconciliation->getDirty()), ['status', 'reversed_at', 'reversed_by', 'updated_at']) !== []) {
                throw new LogicException('A bank reconciliation cannot be edited. Undo it instead.');
            }
        });

        static::deleting(fn () => throw new LogicException('A bank reconciliation is never deleted.'));
    }

    /** @return BelongsTo<CashBankAccount, $this> */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    /** @return HasMany<BankReconciliationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
