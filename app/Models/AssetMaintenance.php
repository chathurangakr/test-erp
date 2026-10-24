<?php

namespace App\Models;

use App\Enums\LoanDocumentStatus;
use App\Enums\PaymentMethod;
use Database\Factories\AssetMaintenanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Work done on an asset — a service, a repair — with what it cost (charged to repairs and
 * maintenance). Immutable; corrected by reversal. See docs/FIXED_ASSETS.md.
 */
class AssetMaintenance extends Model
{
    /** @use HasFactory<AssetMaintenanceFactory> */
    use HasFactory;

    protected $fillable = [
        'maintenance_number',
        'fixed_asset_id',
        'maintenance_date',
        'work_done',
        'done_by',
        'cost',
        'cash_bank_account_id',
        'payment_method',
        'reference',
        'status',
        'journal_entry_id',
        'reversal_journal_entry_id',
        'created_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'maintenance_date' => 'date',
            'cost' => 'decimal:6',
            'payment_method' => PaymentMethod::class,
            // Recorded in one step and corrected by reversal, like a loan payment.
            'status' => LoanDocumentStatus::class,
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $maintenance): void {
            $allowed = ['status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];

            if ($maintenance->getOriginal('journal_entry_id') === null) {
                $allowed[] = 'journal_entry_id';
            }

            $changed = array_diff(array_keys($maintenance->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException('A maintenance record is immutable. Reverse it instead of editing ['.implode(', ', $changed).'].');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Maintenance records are permanent. Reverse one instead.');
        });
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
