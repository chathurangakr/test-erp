<?php

namespace App\Models;

use App\Enums\SupplierInvoiceStatus;
use App\Support\DecimalMath;
use Database\Factories\SupplierInvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A supplier's invoice.
 *
 * A DRAFT owes nothing and has no journal. Posting creates the payable and freezes the
 * document: the commercial terms of a posted invoice are immutable, and only the fields that
 * describe how much of it has since been settled — its status — and its reversal audit may
 * change afterwards.
 *
 * **There is no stored outstanding balance.** What the invoice still owes is derived from its
 * amount less the allocations of payments that are still posted.
 */
class SupplierInvoice extends Model
{
    /** @use HasFactory<SupplierInvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'internal_invoice_number',
        'supplier_id',
        'supplier_invoice_number',
        'supplier_invoice_number_normalised',
        'invoice_date',
        'due_date',
        'fiscal_period_id',
        'status',
        'description',
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
            'status' => SupplierInvoiceStatus::class,
            'invoice_date' => 'date',
            'due_date' => 'date',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * Keep the supplier's own number in a normalised form, so "inv-001" and "INV-001 " are
     * recognised as the same invoice rather than entered twice.
     */
    public function setSupplierInvoiceNumberAttribute(?string $value): void
    {
        $this->attributes['supplier_invoice_number'] = $value === null ? null : trim($value);
        $this->attributes['supplier_invoice_number_normalised'] = self::normaliseNumber($value);
    }

    /**
     * The comparable form of a supplier's invoice number.
     *
     * Case and inner whitespace are ignored, so "inv-001", "INV-001 " and "INV  001" are one
     * invoice rather than three. Validation and the unique index must agree on this, so
     * there is exactly one implementation.
     */
    public static function normaliseNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strtoupper(preg_replace('/\s+/', ' ', trim($value)));
    }

    /**
     * A posted invoice is frozen. Its status may still move as payments arrive, and the
     * reversal fields may be written once — everything else needs a reversing document.
     */
    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $original = $invoice->getOriginal('status');
            $original = $original instanceof SupplierInvoiceStatus ? $original : SupplierInvoiceStatus::tryFrom((string) $original);

            if ($original === null || $original === SupplierInvoiceStatus::Draft) {
                return;
            }

            $allowed = ['status', 'reversal_journal_entry_id', 'reversed_at', 'reversed_by', 'updated_at'];
            $changed = array_diff(array_keys($invoice->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException(
                    'A posted supplier invoice is immutable. Reverse it instead of editing ['
                    .implode(', ', $changed).'].'
                );
            }
        });

        static::deleting(function (self $invoice): void {
            if ($invoice->status !== SupplierInvoiceStatus::Draft) {
                throw new RuntimeException(
                    'Only a draft supplier invoice may be deleted. Posted invoices are permanent.'
                );
            }
        });
    }

    /**
     * @return HasMany<SupplierInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class);
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

    /**
     * The invoice total, summed from its lines.
     */
    public function total(): string
    {
        return DecimalMath::cost($this->lines->reduce(
            fn (string $carry, SupplierInvoiceLine $line): string => DecimalMath::add($carry, DecimalMath::of($line->amount)),
            DecimalMath::zero(),
        ));
    }

    public function isReversed(): bool
    {
        return $this->status === SupplierInvoiceStatus::Reversed;
    }

    /**
     * @param  Builder<SupplierInvoice>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('internal_invoice_number', 'like', '%'.$term.'%')
                ->orWhere('supplier_invoice_number', 'like', '%'.$term.'%')
                ->orWhere('description', 'like', '%'.$term.'%');
        });
    }
}
