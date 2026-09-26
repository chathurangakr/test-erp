<?php

namespace App\Http\Resources\V1\Masters;

use App\Http\Resources\V1\ApiResource;
use App\Models\Warehouse;
use Illuminate\Http\Request;

/**
 * A warehouse. Exactly one active warehouse is the default.
 *
 * @mixin Warehouse
 */
class WarehouseResource extends ApiResource
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
            'description' => $this->description,
            'address' => $this->address,
            'is_active' => $this->is_active,
            'is_default' => $this->is_default,
            'created_at' => $this->timestamp($this->created_at),
            'updated_at' => $this->timestamp($this->updated_at),
        ];
    }
}
