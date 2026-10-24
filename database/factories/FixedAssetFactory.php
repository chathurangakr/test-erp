<?php

namespace Database\Factories;

use App\Enums\AssetCategory;
use App\Enums\AssetFunding;
use App\Enums\FixedAssetStatus;
use App\Models\FixedAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare asset, for screens and access tests. Real assets — which post a journal — are made
 * through App\Actions\Assets\RecordFixedAsset.
 *
 * @extends Factory<FixedAsset>
 */
class FixedAssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'asset_number' => 'AST-'.fake()->unique()->numerify('20260915-######'),
            'name' => fake()->words(2, true),
            'category' => AssetCategory::Machinery,
            'purchase_date' => '2026-09-01',
            'purchase_price' => '250000.000000',
            'funding' => AssetFunding::Existing,
            'status' => FixedAssetStatus::InUse,
            'posted_at' => now(),
        ];
    }
}
