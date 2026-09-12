<?php

namespace App\Models;

use App\Concerns\HasUppercaseCode;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Customer master record.
 *
 * One customer may buy any product the factory sells. `credit_limit` and `credit_days` are
 * agreed terms, not balances — outstanding receivables are derived from posted transactions
 * in a later module and must never be stored on this model.
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
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
        'credit_limit',
        'credit_days',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            // decimal:2 keeps the value a precise string, never a float.
            'credit_limit' => 'decimal:2',
            'credit_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Customer>  $query
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
