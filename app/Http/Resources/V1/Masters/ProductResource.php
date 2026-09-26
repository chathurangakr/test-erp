<?php

namespace App\Http\Resources\V1\Masters;

use App\Http\Resources\V1\ApiResource;
use App\Models\Product;
use Illuminate\Http\Request;

/**
 * A product. Carries no stock figure — stock is derived from the inventory ledger and served
 * by the inventory endpoints.
 *
 * @mixin Product
 */
class ProductResource extends ApiResource
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
            'primary_uom' => $this->ref($this->whenLoaded('primaryUom', fn () => $this->primaryUom, null)),
            'secondary_uom' => $this->ref($this->whenLoaded('secondaryUom', fn () => $this->secondaryUom, null)),
            'is_active' => $this->is_active,
            'created_at' => $this->timestamp($this->created_at),
            'updated_at' => $this->timestamp($this->updated_at),
        ];
    }
}
