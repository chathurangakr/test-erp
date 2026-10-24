<?php

namespace App\Models;

use App\Concerns\HasUppercaseCode;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Warehouse master record.
 *
 * Inventory movements will require a warehouse reference. Exactly one active warehouse is
 * the default; that invariant is maintained by App\Actions\Warehouses\SetDefaultWarehouse.
 */
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    use HasUppercaseCode;

    protected $fillable = [
        'code',
        'name',
        'description',
        'address',
        'is_active',
        'is_default',
        'holds_customer_items',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'holds_customer_items' => 'boolean',
        ];
    }

    /**
     * Real stores: active, and not the place customers' returnable items are counted. This is
     * what every document's warehouse picker offers.
     *
     * @param  Builder<Warehouse>  $query
     */
    public function scopeOperational(Builder $query): void
    {
        $query->where('is_active', true)->where('holds_customer_items', false);
    }

    /**
     * The one warehouse holding returnable items while customers have them, created the first
     * time a bag is lent. Its code comes from configuration. See docs/INVENTORY_RULES.md §18.
     */
    public static function customerCustody(): self
    {
        $existing = static::query()->where('holds_customer_items', true)->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::query()->create([
            'code' => config('masters.customer_custody_warehouse_code'),
            'name' => 'With customers',
            'description' => 'Returnable bags customers are holding. Not a real store.',
            'is_active' => true,
            'is_default' => false,
            'holds_customer_items' => true,
        ]);
    }

    /**
     * @param  Builder<Warehouse>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%");
        });
    }

    /**
     * The single active default warehouse, if one is set.
     */
    public static function default(): ?self
    {
        return static::query()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }
}
