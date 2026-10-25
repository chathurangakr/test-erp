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
        'financial_year_end_month',
        'grni_account_id',
        'trade_payables_account_id',
        'trade_receivables_account_id',
        'production_labour_account_id',
        'wages_account_id',
        'stock_loss_account_id',
        'sales_discount_account_id',
        'customer_advances_account_id',
        'supplier_advances_account_id',
        'loans_account_id',
        'loan_interest_account_id',
        'opening_balance_account_id',
        'fixed_assets_account_id',
        'asset_gain_account_id',
        'asset_loss_account_id',
        'maintenance_account_id',
        'employee_advances_account_id',
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

    /**
     * @return BelongsTo<Account, $this>
     */
    public function tradePayablesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'trade_payables_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function tradeReceivablesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'trade_receivables_account_id');
    }

    /**
     * Credited when production labour is absorbed into inventory (a contra-expense).
     *
     * @return BelongsTo<Account, $this>
     */
    public function productionLabourAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'production_labour_account_id');
    }

    /**
     * The expense salaries are charged to when a payroll is paid.
     *
     * @return BelongsTo<Account, $this>
     */
    public function wagesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'wages_account_id');
    }

    /**
     * The expense damaged stock is written off to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function stockLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'stock_loss_account_id');
    }

    /**
     * Where discounts and quality allowances given to customers are charged (a revenue account
     * that carries a debit balance — it reduces sales).
     *
     * @return BelongsTo<Account, $this>
     */
    public function salesDiscountAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'sales_discount_account_id');
    }

    /**
     * What the factory owes customers who paid before their invoice (a control account).
     *
     * @return BelongsTo<Account, $this>
     */
    public function customerAdvancesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'customer_advances_account_id');
    }

    /**
     * What suppliers owe the factory for money paid before their invoice (a control account).
     *
     * @return BelongsTo<Account, $this>
     */
    public function supplierAdvancesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'supplier_advances_account_id');
    }

    /**
     * The liability every loan sits in (a control account: only loan documents post to it).
     *
     * @return BelongsTo<Account, $this>
     */
    public function loansAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'loans_account_id');
    }

    /**
     * The expense loan interest is charged to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function loanInterestAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'loan_interest_account_id');
    }

    /**
     * The equity that balances a liability brought in already running (a loan taken before the
     * system), until the accountant reclassifies it.
     *
     * @return BelongsTo<Account, $this>
     */
    public function openingBalanceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'opening_balance_account_id');
    }

    /**
     * The asset fixed assets are carried in, at what they cost.
     *
     * @return BelongsTo<Account, $this>
     */
    public function fixedAssetsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'fixed_assets_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function assetGainAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_gain_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function assetLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_loss_account_id');
    }

    /**
     * The expense asset repairs and servicing are charged to.
     *
     * @return BelongsTo<Account, $this>
     */
    public function maintenanceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'maintenance_account_id');
    }

    /**
     * The asset salary advances sit in until a payroll takes them back (a control account).
     *
     * @return BelongsTo<Account, $this>
     */
    public function employeeAdvancesAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'employee_advances_account_id');
    }

    public function isOperationalAccountingEnabled(): bool
    {
        return $this->operational_accounting_start_date !== null;
    }
}
