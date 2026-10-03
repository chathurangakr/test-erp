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
 * The one implementation behind both the website and the API. A chipping run records only
 * its outputs — the operator's measured rows, stored exactly as entered, each in its own
 * product's unit. No raw husk is recorded or consumed here: it is backflushed when the
 * outputs are delivered. There is no husk-to-KG or husk-to-litre conversion anywhere. Waste
 * is information only. Only a DRAFT may be edited.
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

        // Refuses early when no chipping recipe is configured.
        $this->recipe();

        return DB::transaction(function () use ($order, $data, $userId): ProductionOrder {
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

                // Safe only because the order is still a draft — no stock has moved and no
                // output lot exists. Any input line left by the old chipping method goes too.
                $order->inputs()->delete();
                $order->outputs()->delete();
            }

            $this->writeLines($order, $data);

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
     * Writes the operator-entered output rows. Costs are settled at posting.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeLines(ProductionOrder $order, array $data): void
    {
        foreach ($data['outputs'] as $output) {
            $order->outputs()->create([
                'product_id' => $output['product_id'],
                'lot_id' => null,
                'quantity' => DecimalMath::quantity(DecimalMath::of((string) $output['quantity'])),
                'allocation_percent' => DecimalMath::round('0', 6),
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
