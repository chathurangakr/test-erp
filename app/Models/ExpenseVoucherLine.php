<?php

namespace App\Models;

use Database\Factories\ExpenseVoucherLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One expense charged on a voucher.
 *
 * The account is validated against ordinary EXPENSE accounts when the voucher posts, so a
 * request can never name an inventory, receivable, payable, GRNI, revenue or equity account
 * here and move value with no document behind it.
 */
class ExpenseVoucherLine extends Model
{
    /** @use HasFactory<ExpenseVoucherLineFactory> */
    use HasFactory;

    protected $fillable = [
        'expense_voucher_id',
        'expense_account_id',
        'description',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<ExpenseVoucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(ExpenseVoucher::class, 'expense_voucher_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }
}
