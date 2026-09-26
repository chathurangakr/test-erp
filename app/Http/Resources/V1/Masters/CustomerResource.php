<?php

namespace App\Http\Resources\V1\Masters;

use App\Http\Resources\V1\ApiResource;
use App\Models\Customer;
use Illuminate\Http\Request;

/**
 * A customer. `credit_limit` is an agreed term, not a balance — what the customer owes is
 * derived by accounts receivable. It is sent at its stored precision, as a string.
 *
 * @mixin Customer
 */
class CustomerResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'secondary_phone' => $this->secondary_phone,
            'email' => $this->email,
            'city' => $this->city,
            'district' => $this->district,
            'registration_number' => $this->registration_number,
            'tax_number' => $this->tax_number,
            'credit_limit' => $this->credit_limit === null ? null : (string) $this->credit_limit,
            'credit_days' => $this->credit_days,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'created_at' => $this->timestamp($this->created_at),
            'updated_at' => $this->timestamp($this->updated_at),
        ];
    }
}
