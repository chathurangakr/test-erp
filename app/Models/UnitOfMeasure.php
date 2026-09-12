<?php

namespace App\Models;

use Database\Factories\UnitOfMeasureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UnitOfMeasure extends Model
{
    /** @use HasFactory<UnitOfMeasureFactory> */
    use HasFactory;

    protected $table = 'units_of_measure';

    protected $fillable = [
        'code',
        'name',
        'decimal_places',
        'is_active',
    ];

    /**
     * Unit codes are always stored uppercase so lookups and uniqueness are predictable.
     */
    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value === null ? null : mb_strtoupper(trim($value));
    }

    /**
     * Products using this unit as their primary unit of measure.
     *
     * @return HasMany<Product, $this>
     */
    public function primaryProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'primary_uom_id');
    }

    /**
     * Products using this unit as their secondary unit of measure.
     *
     * @return HasMany<Product, $this>
     */
    public function secondaryProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'secondary_uom_id');
    }
}
