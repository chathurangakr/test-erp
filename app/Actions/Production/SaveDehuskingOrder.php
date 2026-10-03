<?php

namespace App\Actions\Production;

use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft dehusking order, or replaces the contents of an existing draft.
 *
 * The one implementation behind both the website and the API. A draft moves no stock. The
 * input product comes from the recipe, never from the request, and no output is written here:
 * output products, quantities and costs are all derived at posting by
 * PostDehuskingProduction. The labour rate per coconut is the operator's figure; the labour
 * COST is calculated from it at posting. Only a DRAFT may be edited.
 */
class SaveDehuskingOrder
{
    public function __construct(private readonly GenerateProductionReference $references) {}

    /**
     * @param  array<string, mixed>  $data  validated by StoreDehuskingRequest / UpdateDehuskingRequest
     */
    public function handle(?ProductionOrder $order, array $data, ?int $userId = null): ProductionOrder
    {
        if ($order !== null) {
            $this->assertEditable($order);
        }

        $recipe = $this->recipe();

        return DB::transaction(function () use ($order, $data, $recipe, $userId): ProductionOrder {
            $header = [
                'production_date' => $data['production_date'],
                'warehouse_id' => $data['warehouse_id'],
                'notes' => $data['notes'] ?? null,
                'labour_rate' => ($data['labour_rate'] ?? null) === null
                    ? null
                    : DecimalMath::cost(DecimalMath::of((string) $data['labour_rate'])),
            ];

            if ($order === null) {
                $order = ProductionOrder::create($header + [
                    'production_number' => $this->references->forProductionOrder(),
                    'production_type' => ProductionType::Dehusking,
                    'status' => ProductionStatus::Draft,
                    'created_by' => $userId ?? auth()->id(),
                ]);
            } else {
                $order->update($header);

                // Safe only because the order is still a draft — nothing has been consumed.
                $order->inputs()->delete();
            }

            $order->inputs()->create([
                'product_id' => $recipe->input_product_id,
                // Pooled coconut has no lot to name (docs/INVENTORY_RULES.md §15).
                'lot_id' => $data['lot_id'] ?? null,
                'quantity' => DecimalMath::quantity(DecimalMath::of($data['quantity'])),
                'unit_cost' => null,
                'total_cost' => null,
            ]);

            return $order->refresh();
        });
    }

    public function assertEditable(ProductionOrder $order): void
    {
        if (! $order->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft production order can be edited. :reference is :status.', [
                    'reference' => $order->production_number,
                    'status' => $order->status->label(),
                ]),
            ]);
        }
    }

    public function recipe(): ProductionRecipe
    {
        $recipe = ProductionRecipe::forType(ProductionType::Dehusking);

        if ($recipe === null) {
            throw ValidationException::withMessages([
                'recipe' => __('No active dehusking recipe is configured.'),
            ]);
        }

        return $recipe;
    }
}
