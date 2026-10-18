<?php

namespace App\Actions\Masters;

use App\Models\Supplier;
use App\Support\SequentialCode;
use Illuminate\Support\Facades\DB;

/**
 * Adds a supplier. Without a code it gets the next one in sequence (SUP-001, SUP-002, …), so
 * nobody has to look up the last code used. Shared by the website and the API.
 */
class CreateSupplier
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Supplier
    {
        return DB::transaction(function () use ($attributes): Supplier {
            if (blank($attributes['code'] ?? null)) {
                $attributes['code'] = $this->nextCode();
            }

            return Supplier::create($attributes);
        });
    }

    public function nextCode(): string
    {
        return SequentialCode::next(Supplier::class, (string) config('masters.supplier_code_prefix'), (int) config('masters.code_digits'));
    }
}
