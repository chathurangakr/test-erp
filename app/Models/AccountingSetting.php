<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single row of accounting configuration.
 *
 * `operational_accounting_start_date` — the cutover — decides whether stock movements
 * generate journals at all. While it is NULL the operational modules behave exactly as they
 * did before Finance existed, which is what lets accounting be switched on against a system
 * that already holds history.
 *
 * See docs/ACCOUNTING_RULES.md §13.
 */
class AccountingSetting extends Model
{
    protected $fillable = [
        'operational_accounting_start_date',
        'grni_account_id',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'operational_accounting_start_date' => 'date',
        ];
    }

    /**
     * The configuration row, or null when accounting has never been configured.
     *
     * Deliberately does NOT create a row on read: an absent row and a row with a NULL
     * cutover mean the same thing — accounting is not switched on — and inventing one on a
     * read would write configuration as a side effect of looking at it.
     */
    public static function current(): ?self
    {
        return self::query()->orderBy('id')->first();
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function grniAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'grni_account_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isOperationalAccountingEnabled(): bool
    {
        return $this->operational_accounting_start_date !== null;
    }
}
