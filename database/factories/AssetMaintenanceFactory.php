<?php

namespace Database\Factories;

use App\Enums\LoanDocumentStatus;
use App\Models\AssetMaintenance;
use App\Models\FixedAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare maintenance record, for screens and access tests. Real ones are made through
 * App\Actions\Assets\RecordAssetMaintenance.
 *
 * @extends Factory<AssetMaintenance>
 */
class AssetMaintenanceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'maintenance_number' => 'MNT-'.fake()->unique()->numerify('20260915-######'),
            'fixed_asset_id' => FixedAsset::factory(),
            'maintenance_date' => '2026-09-15',
            'work_done' => 'Engine service',
            'cost' => '0.000000',
            'status' => LoanDocumentStatus::Posted,
            'posted_at' => now(),
        ];
    }
}
