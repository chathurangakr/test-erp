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
 * Creates a draft husk chipping order, or replaces the contents of an existing draft.
 *
 * The one implementation behind both the website and the API. The input product comes from
 * the recipe; the chip weights are the operator's measured figures, stored exactly as
 * entered — there is no husk-to-KG conversion anywhere. Waste is information only. Costs
 * stay null until PostHuskChippingProduction prices the batch. Only a DRAFT may be edited.
 */
class SaveHuskChippingOrder
{
    public function __construct(private readonly GenerateProductionReference $references) {}

    /**
     * @param  array<string, mixed>  $data  validated by StoreHuskChippingRequest / UpdateHuskChippingRequest
     */
    public function handle(?ProductionOrder $order, array $data, ?int $userId = null): ProductionOrder
    {
        if ($order !== null) {
            $this->assertEditable($order);
        }

        $recipe = $this->recipe();

        return DB::transaction(function () use ($order, $data, $recipe, $userId): ProductionOrder {
            $waste = $this->wasteQuantity($data);

            $header = [
                'production_date' => $data['production_date'],
                'warehouse_id' => $data['warehouse_id'],
                'notes' => $data['notes'] ?? null,
                'waste_quantity' => $waste,
                'waste_uom_id' => $waste === null ? null : $data['waste_uom_id'],
            ];

            if ($order === null) {
                $order = ProductionOrder::create($header + [
                    'production_number' => $this->references->forProductionOrder(),
                    'production_type' => ProductionType::HuskChipping,
                    'status' => ProductionStatus::Draft,
                    'created_by' => $userId ?? auth()->id(),
                ]);
            } else {
                $order->update($header);

                // Safe only because the order is still a draft — nothing has been consumed and
                // no output lot exists.
                $order->inputs()->delete();
                $order->outputs()->delete();
            }

            $this->writeLines($order, $data, $recipe);

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
        $recipe = ProductionRecipe::forType(ProductionType::HuskChipping);

        if ($recipe === null) {
            throw ValidationException::withMessages([
                'recipe' => __('No active husk chipping recipe is configured.'),
            ]);
        }

        return $recipe;
    }

    /**
     * Writes the draft's input and its operator-entered outputs.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeLines(ProductionOrder $order, array $data, ProductionRecipe $recipe): void
    {
        $order->inputs()->create([
            'product_id' => $recipe->input_product_id,
            'lot_id' => $data['lot_id'],
            'quantity' => DecimalMath::quantity(DecimalMath::of($data['quantity'])),
            'unit_cost' => null,
            'total_cost' => null,
        ]);

        $percentages = $recipe->outputs->pluck('allocation_percent', 'product_id');

        foreach ($data['outputs'] as $output) {
            $order->outputs()->create([
                'product_id' => $output['product_id'],
                'lot_id' => null,
                'quantity' => DecimalMath::quantity(DecimalMath::of((string) $output['quantity'])),
                // The configured share, recorded for reference. Posting replaces it with the
                // share actually used, which differs when a grade produced nothing.
                'allocation_percent' => $percentages->get((int) $output['product_id']),
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function wasteQuantity(array $data): ?string
    {
        $waste = $data['waste_quantity'] ?? null;

        if ($waste === null || $waste === '') {
            return null;
        }

        return DecimalMath::quantity(DecimalMath::of((string) $waste));
    }
}
