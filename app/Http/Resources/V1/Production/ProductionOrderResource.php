<?php

namespace App\Http\Resources\V1\Production;

use App\Enums\ProductionStatus;
use App\Http\Resources\V1\ApiResource;
use App\Models\ProductionInput;
use App\Models\ProductionOrder;
use App\Models\ProductionOutput;
use Illuminate\Http\Request;

/**
 * A production run — dehusking, husk chipping or copra — in one shape.
 *
 * Every cost figure is the one the posting action stored from the ledger. A DRAFT has priced
 * nothing, so its input costs, output costs AND allocation shares are null: the share on a
 * draft line is only the configured one, and posting may renormalise it (a grade that
 * produced nothing takes no share). The API reports authoritative figures only.
 *
 * Quantities are exactly as stored — measured weights as the operator entered them, derived
 * counts as the recipe produced them. No unit is ever converted into another.
 *
 * @mixin ProductionOrder
 */
class ProductionOrderResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isDraft = $this->status === ProductionStatus::Draft;
        $priced = $this->status->reachedInventory();

        return [
            'id' => $this->id,
            'production_number' => $this->production_number,
            'production_type' => $this->enum($this->production_type),
            'status' => $this->enum($this->status),
            'production_date' => $this->date($this->production_date),
            'warehouse' => $this->ref($this->whenLoaded('warehouse', fn () => $this->warehouse, null)),
            'notes' => $this->notes,
            // Information only — creates no inventory, no lot and no cost.
            'waste' => $this->waste_quantity === null ? null : [
                'quantity' => $this->quantity($this->waste_quantity),
                'uom' => $this->relationLoaded('wasteUom') ? $this->wasteUom?->code : null,
            ],
            'input_value' => $this->whenLoaded('inputs', fn () => $priced ? $this->money($this->inputValue()) : null),
            'output_value' => $this->whenLoaded('outputs', fn () => $priced ? $this->money($this->outputValue()) : null),
            'actions' => [
                'can_edit' => $this->status->isEditable(),
                'can_post' => $this->status->canPost(),
                'can_reverse' => $this->status->canReverse() && ! $this->isReversed(),
                'can_cancel' => $this->status->canCancel(),
            ],
            'consumption_reference' => $this->whenLoaded('consumptionTransaction', fn () => $this->consumptionTransaction?->reference),
            'output_reference' => $this->whenLoaded('outputTransaction', fn () => $this->outputTransaction?->reference),
            'created_by' => $this->userRef($this->whenLoaded('createdBy', fn () => $this->createdBy, null)),
            'posted_by' => $this->userRef($this->whenLoaded('postedBy', fn () => $this->postedBy, null)),
            'posted_at' => $this->timestamp($this->posted_at),
            'reversed_by' => $this->userRef($this->whenLoaded('reversedBy', fn () => $this->reversedBy, null)),
            'reversed_at' => $this->timestamp($this->reversed_at),
            'created_at' => $this->timestamp($this->created_at),
            'inputs' => $this->whenLoaded('inputs', fn () => $this->inputs->map(fn (ProductionInput $input): array => [
                'id' => $input->id,
                'product' => $this->ref($input->relationLoaded('product') ? $input->product : null),
                'uom' => $input->product?->primaryUom?->code,
                'lot' => $input->relationLoaded('lot') && $input->lot !== null
                    ? ['id' => $input->lot->id, 'lot_number' => $input->lot->lot_number]
                    : null,
                'quantity' => $this->quantity($input->quantity),
                'unit_cost' => $isDraft ? null : $this->money($input->unit_cost),
                'total_cost' => $isDraft ? null : $this->money($input->total_cost),
            ])->values()),
            'outputs' => $this->whenLoaded('outputs', fn () => $this->outputs->map(fn (ProductionOutput $output): array => [
                'id' => $output->id,
                'product' => $this->ref($output->relationLoaded('product') ? $output->product : null),
                'uom' => $output->product?->primaryUom?->code,
                'secondary_uom' => $output->product?->secondaryUom?->code,
                'lot' => $output->relationLoaded('lot') && $output->lot !== null
                    ? ['id' => $output->lot->id, 'lot_number' => $output->lot->lot_number]
                    : null,
                'quantity' => $this->quantity($output->quantity),
                'secondary_quantity' => $this->quantity($output->secondary_quantity),
                // The share actually applied at posting, never the configured draft value.
                'allocation_percent' => $isDraft || $output->allocation_percent === null ? null : (string) $output->allocation_percent,
                'allocated_cost' => $isDraft ? null : $this->money($output->allocated_cost),
                'unit_cost' => $isDraft ? null : $this->money($output->unit_cost),
                // Informational only — never stored, never a weighted average (DATA_MODEL P-10).
                'informational_secondary_unit_cost' => $isDraft ? null : $output->informationalSecondaryUnitCost(),
            ])->values()),
        ];
    }
}
