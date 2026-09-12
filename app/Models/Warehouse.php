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
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
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
