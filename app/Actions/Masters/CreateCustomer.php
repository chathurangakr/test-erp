<?php

namespace App\Actions\Masters;

use App\Models\Customer;
use App\Support\SequentialCode;
use Illuminate\Support\Facades\DB;

/**
 * Adds a customer. Without a code it gets the next one in sequence (CUS-001, CUS-002, …), so
 * nobody has to look up the last code used. Shared by the website and the API.
 */
class CreateCustomer
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(array $attributes): Customer
    {
        return DB::transaction(function () use ($attributes): Customer {
            if (blank($attributes['code'] ?? null)) {
                $attributes['code'] = $this->nextCode();
            }

            return Customer::create($attributes);
        });
    }

    public function nextCode(): string
    {
        return SequentialCode::next(Customer::class, (string) config('masters.customer_code_prefix'), (int) config('masters.code_digits'));
    }
}
