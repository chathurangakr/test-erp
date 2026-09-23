<?php

namespace App\Models;

use App\Enums\CashBankAccountKind;
use Database\Factories\CashBankAccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A general-ledger account the factory treats as real cash or a real bank account.
 *
 * **There is no balance column here, and there must never be one.** What is in the till or
 * the bank is derived from posted journal lines by `AccountingBalanceService`, for the same
 * reason stock is derived from the inventory ledger and an account carries no balance: a
 * stored total has no history, and a drifted one cannot be explained.
 *
 * This row is configuration. It says which accounts money may legitimately move through, so
 * supplier payments, customer receipts, expense vouchers and fund transfers all consult one
 * list rather than each deciding for itself what an acceptable asset account looks like.
 *
 * Deactivated, never deleted — historic documents must keep referring to something
 * meaningful.
 */
class CashBankAccount extends Model
{
    /** @use HasFactory<CashBankAccountFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id',
        'account_kind',
        'display_name',
        'bank_name',
        'branch_name',
        'account_number',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'account_kind' => CashBankAccountKind::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * A configuration that money has moved through is permanent.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $configuration): void {
            $used = $configuration->expenseVouchers()->exists()
                || $configuration->transfersOut()->exists()
                || $configuration->transfersIn()->exists();

            if ($used) {
                throw new RuntimeException(
                    'Cash/bank account ['.$configuration->display_name.'] has document history and cannot be deleted. Deactivate it instead.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<ExpenseVoucher, $this>
     */
    public function expenseVouchers()
    {
        return $this->hasMany(ExpenseVoucher::class);
    }

    /**
     * @return HasMany<FundTransfer, $this>
     */
    public function transfersOut()
    {
        return $this->hasMany(FundTransfer::class, 'from_cash_bank_account_id');
    }

    /**
     * @return HasMany<FundTransfer, $this>
     */
    public function transfersIn()
    {
        return $this->hasMany(FundTransfer::class, 'to_cash_bank_account_id');
    }

    public function isCash(): bool
    {
        return $this->account_kind === CashBankAccountKind::Cash;
    }

    /**
     * @param  Builder<CashBankAccount>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('display_name', 'like', '%'.$term.'%')
                ->orWhere('bank_name', 'like', '%'.$term.'%')
                ->orWhere('account_number', 'like', '%'.$term.'%')
                ->orWhereHas('account', fn (Builder $account) => $account->search($term));
        });
    }
}
