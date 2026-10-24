<?php

namespace App\Actions\Assets;

use App\Models\AssetMaintenance;
use App\Models\FixedAsset;
use App\Support\DailyDocumentSequence;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Asset document numbers: AST-YYYYMMDD-000001 for assets and MNT-YYYYMMDD-000001 for maintenance.
 */
class GenerateAssetReference
{
    public function __construct(private readonly DailyDocumentSequence $sequence) {}

    public function forAsset(CarbonInterface $date): string
    {
        return $this->sequence->next('AST', FixedAsset::class, 'asset_number', Carbon::instance($date));
    }

    public function forMaintenance(CarbonInterface $date): string
    {
        return $this->sequence->next('MNT', AssetMaintenance::class, 'maintenance_number', Carbon::instance($date));
    }
}
