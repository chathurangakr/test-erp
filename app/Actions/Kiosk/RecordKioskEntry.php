<?php

namespace App\Actions\Kiosk;

use App\Actions\Production\PostCopraProduction;
use App\Actions\Production\PostDehuskingProduction;
use App\Actions\Production\PostHuskChippingProduction;
use App\Actions\Production\SaveCopraOrder;
use App\Actions\Production\SaveDehuskingOrder;
use App\Actions\Production\SaveHuskChippingOrder;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\SaveGoodsReceipt;
use App\Actions\Sales\PostDelivery;
use App\Actions\Sales\SaveDelivery;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\ProductionOrder;
use Illuminate\Support\Facades\DB;

/**
 * Simple mode's "Done": save the document and post it, as ONE event.
 *
 * Staff on the kiosk never see drafts. Each method composes the existing Save* action and the
 * existing Post* action inside a single database transaction, so either the document is posted
 * and stock has moved, or nothing at all was written — a failed post (not enough stock, a lot
 * that does not match) never leaves an orphaned draft behind.
 *
 * No business rule lives here: costing, lot checks, negative-stock prevention, derived outputs
 * and journals all happen in the actions this class calls, exactly as for the full ERP and the
 * mobile API. Callers must already hold both the create and the post permission.
 */
class RecordKioskEntry
{
    public function __construct(
        private readonly SaveGoodsReceipt $saveReceipt,
        private readonly PostGoodsReceipt $postReceipt,
        private readonly SaveDelivery $saveDelivery,
        private readonly PostDelivery $postDelivery,
        private readonly SaveDehuskingOrder $saveDehusking,
        private readonly PostDehuskingProduction $postDehusking,
        private readonly SaveHuskChippingOrder $saveChipping,
        private readonly PostHuskChippingProduction $postChipping,
        private readonly SaveCopraOrder $saveCopra,
        private readonly PostCopraProduction $postCopra,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function receive(array $data, int $userId): GoodsReceipt
    {
        return DB::transaction(fn (): GoodsReceipt => $this->postReceipt->handle(
            $this->saveReceipt->handle(null, $data, $userId),
            $userId,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function dispatch(array $data, int $userId): Delivery
    {
        return DB::transaction(fn (): Delivery => $this->postDelivery->handle(
            $this->saveDelivery->handle(null, $data, $userId),
            $userId,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function dehusk(array $data, int $userId): ProductionOrder
    {
        return DB::transaction(fn (): ProductionOrder => $this->postDehusking->handle(
            $this->saveDehusking->handle(null, $data, $userId),
            $userId,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function chip(array $data, int $userId): ProductionOrder
    {
        return DB::transaction(fn (): ProductionOrder => $this->postChipping->handle(
            $this->saveChipping->handle(null, $data, $userId),
            $userId,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function copra(array $data, int $userId): ProductionOrder
    {
        return DB::transaction(fn (): ProductionOrder => $this->postCopra->handle(
            $this->saveCopra->handle(null, $data, $userId),
            $userId,
        ));
    }
}
