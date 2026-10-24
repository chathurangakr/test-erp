<?php

namespace App\Actions\Production;

use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Models\ProductionOrder;
use App\Models\ProductionRecipe;
use App\Models\ProductionRecipeOutput;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft copra order, or replaces the contents of an existing draft.
 *
 * The one implementation behind both the website and the API. The input product comes from
 * the recipe. A DERIVED output's quantity — the one-shell-per-coconut count — is calculated
 * here from the quantity processed and the recipe ratio; the submitted value is refused by the
 * form request, so there is nothing to fall back to. A MEASURED quantity (copra KG, shell KG)
 * is stored exactly as entered, with no NOS↔KG conversion. Costs stay null until
 * PostCopraProduction prices the batch. Only a DRAFT may be edited.
 */
class SaveCopraOrder
{
    public function __construct(private readonly GenerateProductionReference $references) {}

    /**
     * @param  array<string, mixed>  $data  validated by StoreCopraRequest / UpdateCopraRequest
     */
    public function handle(?ProductionOrder $order, array $data, ?int $userId = null): ProductionOrder
    {
        if ($order !== null) {
            $this->assertEditable($order);
        }

        // The coconut size chosen; a draft edited without naming one keeps its own.
        $recipe = $this->recipe(isset($data['input_product_id'])
            ? (int) $data['input_product_id']
            : $order?->inputs()->value('product_id'));

        return DB::transaction(function () use ($order, $data, $recipe, $userId): ProductionOrder {
            $header = [
                'production_date' => $data['production_date'],
                'warehouse_id' => $data['warehouse_id'],
                'notes' => $data['notes'] ?? null,
            ];

            if ($order === null) {
                $order = ProductionOrder::create($header + [
                    'production_number' => $this->references->forProductionOrder(),
                    'production_type' => ProductionType::Copra,
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

    /**
     * The active recipe for an input product (a coconut size), or the type's original.
     */
    public function recipe(?int $inputProductId = null): ProductionRecipe
    {
        $recipe = ProductionRecipe::forType(ProductionType::Copra, $inputProductId);

        if ($recipe === null) {
            throw ValidationException::withMessages([
                'recipe' => __('No active copra production recipe is configured.'),
            ]);
        }

        return $recipe;
    }

    /**
     * Writes the draft's input and its outputs.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeLines(ProductionOrder $order, array $data, ProductionRecipe $recipe): void
    {
        $processed = DecimalMath::quantity(DecimalMath::of($data['quantity']));

        $order->inputs()->create([
            'product_id' => $recipe->input_product_id,
            // Pooled dehusked coconut has no lot to name (docs/INVENTORY_RULES.md §15).
            'lot_id' => $data['lot_id'] ?? null,
            'quantity' => $processed,
            'unit_cost' => null,
            'total_cost' => null,
        ]);

        $configured = $recipe->outputs->keyBy(fn (ProductionRecipeOutput $output): int => (int) $output->product_id);

        foreach ($data['outputs'] as $output) {
            $config = $configured->get((int) $output['product_id']);

            if ($config === null) {
                continue;
            }

            $order->outputs()->create([
                'product_id' => $config->product_id,
                'lot_id' => null,
                'quantity' => $config->derivesQuantity()
                    ? DecimalMath::quantity(DecimalMath::mul($processed, DecimalMath::of($config->output_ratio)))
                    : DecimalMath::quantity(DecimalMath::of((string) $output['quantity'])),
                // Required or optional per the recipe; kept whenever it was measured.
                'secondary_quantity' => filled($output['secondary_quantity'] ?? null)
                    ? DecimalMath::quantity(DecimalMath::of((string) $output['secondary_quantity']))
                    : null,
                // The configured share, recorded for reference. Posting stores the share
                // actually used.
                'allocation_percent' => $config->allocation_percent,
                'allocated_cost' => null,
                'unit_cost' => null,
            ]);
        }
    }
}
