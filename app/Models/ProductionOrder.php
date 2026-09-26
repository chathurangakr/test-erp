<?php

namespace App\Models;

use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Support\DecimalMath;
use Database\Factories\ProductionOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Production order.
 *
 * A DRAFT moves no stock. Posting writes two inventory transactions — a
 * PRODUCTION_CONSUMPTION for the input and a PRODUCTION_OUTPUT for the outputs — inside one
 * database transaction, and freezes the document.
 */
class ProductionOrder extends Model
{
    /** @use HasFactory<ProductionOrderFactory> */
    use HasFactory;

    protected $fillable = [
        'production_number',
        'production_type',
        'production_date',
        'warehouse_id',
        'status',
        'notes',
        'waste_quantity',
        'waste_uom_id',
        'inventory_transaction_id',
        'output_inventory_transaction_id',
        'reversal_inventory_transaction_id',
        'output_reversal_transaction_id',
        'created_by',
        'posted_by',
        'posted_at',
        'reversed_by',
        'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'production_type' => ProductionType::class,
            'status' => ProductionStatus::class,
            'production_date' => 'date',
            'waste_quantity' => 'decimal:3',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /**
     * A posted production order is frozen. Only the reversal audit fields may change.
     */
    protected static function booted(): void
    {
        static::updating(function (self $order): void {
            $original = $order->getOriginal('status');
            $wasPosted = $original === ProductionStatus::Posted->value
                || $original === ProductionStatus::Posted;

            if (! $wasPosted) {
                return;
            }

            $allowed = [
                'status',
                'reversal_inventory_transaction_id',
                'output_reversal_transaction_id',
                'reversed_at',
                'reversed_by',
                'updated_at',
            ];
            $changed = array_diff(array_keys($order->getDirty()), $allowed);

            if ($changed !== []) {
                throw new RuntimeException(
                    'A posted production order is immutable. Reverse it instead of editing ['
                    .implode(', ', $changed).'].'
                );
            }
        });

        static::deleting(function (self $order): void {
            if ($order->status !== ProductionStatus::Draft) {
                throw new RuntimeException(
                    'Only a draft production order may be deleted. Posted production is permanent.'
                );
            }
        });
    }

    /**
     * @return HasMany<ProductionInput, $this>
     */
    public function inputs(): HasMany
    {
        return $this->hasMany(ProductionInput::class);
    }

    /**
     * @return HasMany<ProductionOutput, $this>
     */
    public function outputs(): HasMany
    {
        return $this->hasMany(ProductionOutput::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * The unit the recorded waste was measured in.
     *
     * Waste is information only — it creates no inventory — so this points at the real UOM
     * master rather than inventing a unit. See docs/BUSINESS_RULES.md §19.
     *
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function wasteUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'waste_uom_id');
    }

    /**
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function consumptionTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'inventory_transaction_id');
    }

    /**
     * @return BelongsTo<InventoryTransaction, $this>
     */
    public function outputTransaction(): BelongsTo
    {
        return $this->belongsTo(InventoryTransaction::class, 'output_inventory_transaction_id');
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

    /** Total value consumed. Zero until posted. */
    public function inputValue(): string
    {
        return DecimalMath::cost($this->inputs->reduce(
            fn (string $carry, ProductionInput $input): string => DecimalMath::add(
                $carry,
                DecimalMath::of($input->total_cost ?? '0'),
            ),
            DecimalMath::zero(),
        ));
    }

    /** Total value assigned to outputs. Must equal inputValue() exactly. */
    public function outputValue(): string
    {
        return DecimalMath::cost($this->outputs->reduce(
            fn (string $carry, ProductionOutput $output): string => DecimalMath::add(
                $carry,
                DecimalMath::of($output->allocated_cost ?? '0'),
            ),
            DecimalMath::zero(),
        ));
    }

    public function inputQuantity(): string
    {
        return DecimalMath::quantity($this->inputs->reduce(
            fn (string $carry, ProductionInput $input): string => DecimalMath::add(
                $carry,
                DecimalMath::of($input->quantity),
            ),
            DecimalMath::zero(),
        ));
    }

    public function isReversed(): bool
    {
        return $this->reversal_inventory_transaction_id !== null;
    }

    /**
     * @param  Builder<ProductionOrder>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where('production_number', 'like', "%{$term}%");
    }
}
