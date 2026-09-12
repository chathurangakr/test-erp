<?php

namespace App\Models;

use App\Concerns\HasUppercaseCode;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Supplier master record.
 *
 * One supplier may supply any material — whole coconut, raw husk, or anything added later.
 * Purchases and payments will reference suppliers from their own tables; this model
 * deliberately holds no balance or accounting state.
 */
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    use HasUppercaseCode;

    protected $fillable = [
        'code',
        'name',
        'contact_person',
        'phone',
        'secondary_phone',
        'email',
        'address_line_1',
        'address_line_2',
        'city',
        'district',
        'registration_number',
        'tax_number',
        'payment_terms_days',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Free-text search across the fields a user is most likely to type.
     *
     * @param  Builder<Supplier>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $query->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('contact_person', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('city', 'like', "%{$term}%");
        });
    }
}
