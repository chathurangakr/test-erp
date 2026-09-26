<?php

namespace App\Http\Resources\V1\Masters;

use App\Http\Resources\V1\ApiResource;
use App\Models\Supplier;
use Illuminate\Http\Request;

/**
 * A supplier. Carries no payable balance — what is owed is derived by accounts payable.
 *
 * @mixin Supplier
 */
class SupplierResource extends ApiResource
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
            'payment_terms_days' => $this->payment_terms_days,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'created_at' => $this->timestamp($this->created_at),
            'updated_at' => $this->timestamp($this->updated_at),
        ];
    }
}
