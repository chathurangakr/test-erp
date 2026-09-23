<?php

namespace App\Models;

use App\Enums\SalesInvoiceStatus;
use App\Support\DecimalMath;
use Database\Factories\SalesInvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A sales invoice — where revenue is recognised.
 *
 * A DRAFT is owed nothing and has no journal. Posting creates the receivable and freezes the
 * document: only its status, as receipts arrive, and its reversal audit may change afterwards.
 *
 * **There is no stored outstanding balance.** What the invoice is still owed is derived from
 * its lines less the allocations of receipts that are still posted.
 */
class SalesInvoice extends Model
{
    /** @use HasFactory<SalesInvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'invoice_date',
        'due_date',
        'fiscal_period_id',
        'customer_reference',
        'description',
        'status',
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
            'status' => SalesInvoiceStatus::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * A posted invoice is frozen. Its status may still move as receipts arrive, and the
     * reversal fields may be written once — everything else needs a reversing document.
     */
    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $original = $invoice->getOriginal('status');
            $original = $original instanceof SalesInvoiceStatus ? $original : SalesInvoiceStatus::tryFrom((string) $original);

            if ($original === null || $original === SalesInvoiceStatus::Draft) {
                return;
            }

            $allowed = ['status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];
            $changed = array_diff(array_keys($invoice->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException(
                    'A posted sales invoice is immutable. Reverse it instead of editing ['
                    .implode(', ', $changed).'].'
                );
            }
        });

        static::deleting(function (self $invoice): void {
            if ($invoice->status !== SalesInvoiceStatus::Draft) {
                throw new RuntimeException(
                    'Only a draft sales invoice may be deleted. Posted invoices are permanent.'
                );
            }
        });
    }

    /**
     * @return HasMany<SalesInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class);
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

    public function total(): string
    {
        return DecimalMath::cost($this->lines->reduce(
            fn (string $carry, SalesInvoiceLine $line): string => DecimalMath::add($carry, DecimalMath::of($line->amount)),
            DecimalMath::zero(),
        ));
    }

    public function isReversed(): bool
    {
        return $this->status === SalesInvoiceStatus::Reversed;
    }

    /**
     * @param  Builder<SalesInvoice>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('invoice_number', 'like', '%'.$term.'%')
                ->orWhere('customer_reference', 'like', '%'.$term.'%')
                ->orWhere('description', 'like', '%'.$term.'%');
        });
    }
}
