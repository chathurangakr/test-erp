<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\SupplierPaymentStatus;
use App\Support\DecimalMath;
use Database\Factories\SupplierPaymentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Money paid to a supplier.
 *
 * Always allocated to invoices: Version 1 has no prepayments, so the allocated total equals
 * the payment exactly. A posted payment is immutable and is corrected by reversal.
 */
class SupplierPayment extends Model
{
    /** @use HasFactory<SupplierPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'payment_number',
        'supplier_id',
        'payment_date',
        'fiscal_period_id',
        'payment_account_id',
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
            'status' => SupplierPaymentStatus::class,
            'payment_method' => PaymentMethod::class,
            'cheque_date' => 'date',
            'payment_date' => 'date',
            'amount' => 'decimal:6',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $payment): void {
            $original = $payment->getOriginal('status');
            $original = $original instanceof SupplierPaymentStatus ? $original : SupplierPaymentStatus::tryFrom((string) $original);

            if ($original !== SupplierPaymentStatus::Posted) {
                return;
            }

            $allowed = ['status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];
            $changed = array_diff(array_keys($payment->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException(
                    'A posted supplier payment is immutable. Reverse it instead of editing ['
                    .implode(', ', $changed).'].'
                );
            }
        });

        static::deleting(function (self $payment): void {
            if ($payment->status !== SupplierPaymentStatus::Draft) {
                throw new RuntimeException(
                    'Only a draft supplier payment may be deleted. Posted payments are permanent.'
                );
            }
        });
    }

    /**
     * @return HasMany<SupplierPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
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
            fn (string $carry, SupplierPaymentAllocation $allocation): string => DecimalMath::add(
                $carry,
                DecimalMath::of($allocation->amount),
            ),
            DecimalMath::zero(),
        ));
    }

    /**
     * @param  Builder<SupplierPayment>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('payment_number', 'like', '%'.$term.'%')
                ->orWhere('reference', 'like', '%'.$term.'%');
        });
    }
}
