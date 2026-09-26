<?php

namespace App\Actions\Purchasing;

use App\Enums\GoodsReceiptStatus;
use App\Models\GoodsReceipt;
use App\Support\DecimalMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a draft goods receipt, or replaces the contents of an existing draft.
 *
 * The one implementation behind both the website and the API. A draft moves no stock and
 * carries no calculated cost: material cost, landed-cost allocation and final unit cost stay
 * zero until PostGoodsReceipt calculates them. Only a DRAFT may be edited.
 */
class SaveGoodsReceipt
{
    public function __construct(private readonly GeneratePurchasingReference $references) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?GoodsReceipt $receipt, array $data, ?int $userId = null): GoodsReceipt
    {
        if ($receipt !== null) {
            $this->assertEditable($receipt);
        }

        return DB::transaction(function () use ($receipt, $data, $userId): GoodsReceipt {
            $header = [
                'supplier_id' => $data['supplier_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'receipt_date' => $data['receipt_date'],
                'supplier_delivery_reference' => $data['supplier_delivery_reference'] ?? null,
                'transport_cost' => DecimalMath::cost(DecimalMath::of($data['transport_cost'])),
                'loading_cost' => DecimalMath::cost(DecimalMath::of($data['loading_cost'])),
                'other_cost' => DecimalMath::cost(DecimalMath::of($data['other_cost'])),
                'notes' => $data['notes'] ?? null,
            ];

            if ($receipt === null) {
                $receipt = GoodsReceipt::create($header + [
                    'grn_number' => $this->references->forGoodsReceipt(),
                    'status' => GoodsReceiptStatus::Draft,
                    'created_by' => $userId ?? auth()->id(),
                ]);
            } else {
                $receipt->update($header);

                // Safe only because the receipt is still a draft — no inventory exists yet.
                $receipt->lines()->delete();
            }

            foreach ($data['lines'] as $line) {
                $receipt->lines()->create([
                    'product_id' => $line['product_id'],
                    'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                    'quantity' => DecimalMath::quantity(DecimalMath::of($line['quantity'])),
                    'secondary_quantity' => ($line['secondary_quantity'] ?? null) === null
                        ? null
                        : DecimalMath::quantity(DecimalMath::of($line['secondary_quantity'])),
                    'unit_price' => DecimalMath::cost(DecimalMath::of($line['unit_price'])),
                    'supplier_lot_number' => $line['supplier_lot_number'] ?? null,
                    // Cost columns stay zero until posting calculates them.
                    'material_cost' => '0.000000',
                    'allocated_landed_cost' => '0.000000',
                    'final_unit_cost' => '0.000000',
                ]);
            }

            return $receipt->refresh();
        });
    }

    public function assertEditable(GoodsReceipt $receipt): void
    {
        if (! $receipt->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => __('Only a draft goods receipt can be edited. :reference is :status.', [
                    'reference' => $receipt->grn_number,
                    'status' => $receipt->status->label(),
                ]),
            ]);
        }
    }
}
