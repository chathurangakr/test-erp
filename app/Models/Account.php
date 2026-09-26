<?php

namespace App\Models;

use App\Concerns\HasUppercaseCode;
use App\Enums\AccountType;
use App\Enums\NormalBalance;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One account in the chart.
 *
 * **There is no balance column, and there must never be one.** A balance is derived from
 * posted journal lines by App\Services\Accounting\AccountingBalanceService, for the same
 * reason stock is derived from the inventory ledger: a stored total has no history, and a
 * drifted one cannot be explained.
 *
 * Accounts are deactivated, never deleted — historic journal lines must keep referring to
 * something meaningful.
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    use HasUppercaseCode;

    protected $fillable = [
        'code',
        'name',
        'account_type',
        'parent_id',
        'normal_balance',
        'is_control_account',
        'allow_manual_posting',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'is_control_account' => 'boolean',
            'allow_manual_posting' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * An account that has been posted to is permanent.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $account): void {
            if ($account->lines()->exists()) {
                throw new RuntimeException(
                    'Account ['.$account->code.'] has journal history and cannot be deleted. Deactivate it instead.'
                );
            }

            if ($account->children()->exists()) {
                throw new RuntimeException(
                    'Account ['.$account->code.'] has child accounts and cannot be deleted.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * The product mappings that name this account as their inventory account.
     *
     * Several products share one account — all three husk-chip grades post to Husk Chips
     * Inventory — which is why inventory reconciliation groups by account, never by product.
     *
     * @return HasMany<ProductAccountMapping, $this>
     */
    public function mappedProducts(): HasMany
    {
        return $this->hasMany(ProductAccountMapping::class, 'inventory_account_id');
    }

    /**
     * Whether a person may put this account on a manual journal.
     *
     * Control accounts are excluded by default: they are reconciled from a subsidiary
     * ledger, and a hand-written line would put the control account and its subsidiary out
     * of step with nothing to explain the difference.
     */
    public function acceptsManualPosting(): bool
    {
        return $this->is_active && $this->allow_manual_posting && ! $this->is_control_account;
    }

    /**
     * @param  Builder<Account>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('code', 'like', '%'.$term.'%')
                ->orWhere('name', 'like', '%'.$term.'%');
        });
    }
}
