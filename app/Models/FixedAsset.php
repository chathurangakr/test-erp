<?php

namespace App\Models;

use App\Enums\AssetCategory;
use App\Enums\AssetFunding;
use App\Enums\FixedAssetStatus;
use App\Enums\PaymentMethod;
use Database\Factories\FixedAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Something the factory owns and uses — a vehicle, a machine, a building. Valued at what it cost
 * (no depreciation, by the owner's choice) until it is sold. The purchase figures never change;
 * the name, category and description may be corrected. See docs/FIXED_ASSETS.md.
 */
class FixedAsset extends Model
{
    /** @use HasFactory<FixedAssetFactory> */
    use HasFactory;

    public const EDITABLE = ['name', 'category', 'description'];

    /** Written by a sale and cleared again when that sale is reversed. */
    private const SALE = ['sold_date', 'sale_price', 'buyer', 'sale_cash_bank_account_id', 'sale_payment_method', 'sale_journal_entry_id'];

    protected $fillable = [
        'asset_number',
        'name',
        'category',
        'description',
        'purchase_date',
        'purchase_price',
        'funding',
        'down_payment',
        'cash_bank_account_id',
        'payment_method',
        'loan_id',
        'status',
        'journal_entry_id',
        'reversal_journal_entry_id',
        'sold_date',
        'sale_price',
        'buyer',
        'sale_cash_bank_account_id',
        'sale_payment_method',
        'sale_journal_entry_id',
        'created_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => AssetCategory::class,
            'purchase_date' => 'date',
            'purchase_price' => 'decimal:6',
            'funding' => AssetFunding::class,
            'down_payment' => 'decimal:6',
            'payment_method' => PaymentMethod::class,
            'status' => FixedAssetStatus::class,
            'sold_date' => 'date',
            'sale_price' => 'decimal:6',
            'sale_payment_method' => PaymentMethod::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $asset): void {
            $allowed = [...self::EDITABLE, ...self::SALE, 'status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];

            // The purchase journal and its loan are attached once, straight after creation.
            foreach (['journal_entry_id', 'loan_id'] as $pointer) {
                if ($asset->getOriginal($pointer) === null) {
                    $allowed[] = $pointer;
                }
            }

            $changed = array_diff(array_keys($asset->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException('An asset\'s purchase figures are immutable. Reverse it instead of editing ['.implode(', ', $changed).'].');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Assets are permanent. Reverse one instead.');
        });
    }

    /**
     * @return HasMany<AssetMaintenance, $this>
     */
    public function maintenances(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class);
    }

    /**
     * @return BelongsTo<Loan, $this>
     */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function saleCashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class, 'sale_cash_bank_account_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function saleJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'sale_journal_entry_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
