<?php

namespace App\Actions\Warehouses;

use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a warehouse master record.
 *
 * The one implementation behind both the website and the API. `is_default` is never written
 * directly: when the request asks for this warehouse to become the default, SetDefaultWarehouse
 * does it, so exactly one active default always exists. See docs/DATA_MODEL.md decision D-2.
 */
class SaveWarehouse
{
    public function __construct(private readonly SetDefaultWarehouse $setDefault) {}

    /**
     * @param  array<string, mixed>  $data  validated by Store/UpdateWarehouseRequest
     */
    public function handle(?Warehouse $warehouse, array $data): Warehouse
    {
        $shouldBeDefault = (bool) ($data['is_default'] ?? false);
        unset($data['is_default']);

        return DB::transaction(function () use ($warehouse, $data, $shouldBeDefault): Warehouse {
            if ($warehouse === null) {
                $warehouse = Warehouse::create($data);

                if ($shouldBeDefault) {
                    // Reload so database-level defaults (is_active) are present before the
                    // action checks them.
                    $this->setDefault->handle($warehouse->refresh());
                }

                return $warehouse->refresh();
            }

            $warehouse->update($data);

            if ($shouldBeDefault && ! $warehouse->is_default) {
                $this->setDefault->handle($warehouse);
            }

            return $warehouse->refresh();
        });
    }
}
