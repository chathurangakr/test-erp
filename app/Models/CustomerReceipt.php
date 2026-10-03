<?php

namespace App\Models;

use App\Enums\CustomerReceiptStatus;
use App\Enums\PaymentMethod;
use App\Support\DecimalMath;
use Database\Factories\CustomerReceiptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Money received from a customer.
 *
 * Always allocated to invoices: Version 1 has no deposits or unallocated receipts, so the
 * allocated total equals the receipt exactly. A posted receipt is immutable and is corrected
 * by reversal.
 */
class CustomerReceipt extends Model
{
    /** @use HasFactory<CustomerReceiptFactory> */
    use HasFactory;

    protected $fillable = [
        'receipt_number',
        'customer_id',
        'receipt_date',
        'fiscal_period_id',
        'receipt_account_id',
        'reference',
        'amount',
        'payment_method',
        'cheque_number',
        'cheque_date',
        'cheque_bank',
        'status',
        'notes',
        'journal_entry_id',
        'reversal_journal_entry_id',
        'created_by',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CustomerReceiptStatus::class,
            'payment_method' => PaymentMethod::class,
            'cheque_date' => 'date',
            'receipt_date' => 'date',
            'amount' => 'decimal:6',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $receipt): void {
            $original = $receipt->getOriginal('status');
            $original = $original instanceof CustomerReceiptStatus ? $original : CustomerReceiptStatus::tryFrom((string) $original);

            if ($original !== CustomerReceiptStatus::Posted) {
                return;
            }

            $allowed = ['status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];
            $changed = array_diff(array_keys($receipt->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException(
                    'A posted customer receipt is immutable. Reverse it instead of editing ['
                    .implode(', ', $changed).'].'
                );
            }
        });

        static::deleting(function (self $receipt): void {
            if ($receipt->status !== CustomerReceiptStatus::Draft) {
                throw new RuntimeException(
                    'Only a draft customer receipt may be deleted. Posted receipts are permanent.'
                );
            }
        });
    }

    /**
     * @return HasMany<CustomerReceiptAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(CustomerReceiptAllocation::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function receiptAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receipt_account_id');
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
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
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function allocatedTotal(): string
    {
        return DecimalMath::cost($this->allocations->reduce(
            fn (string $carry, CustomerReceiptAllocation $allocation): string => DecimalMath::add(
                $carry,
                DecimalMath::of($allocation->amount),
            ),
            DecimalMath::zero(),
        ));
    }

    /**
     * @param  Builder<CustomerReceipt>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('receipt_number', 'like', '%'.$term.'%')
                ->orWhere('reference', 'like', '%'.$term.'%');
        });
    }
}
