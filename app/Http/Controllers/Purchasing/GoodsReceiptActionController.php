<?php

namespace App\Http\Controllers\Purchasing;

use App\Actions\Purchasing\CancelGoodsReceipt;
use App\Actions\Purchasing\PostGoodsReceipt;
use App\Actions\Purchasing\ReverseGoodsReceipt;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Posting and reversing goods receipts.
 *
 * All inventory work happens in the action classes; this controller only translates the
 * result into a response.
 */
class GoodsReceiptActionController extends Controller
{
    public function post(GoodsReceipt $goodsReceipt, PostGoodsReceipt $action): RedirectResponse
    {
        $action->handle($goodsReceipt);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Goods receipt posted to inventory.')]);

        return back();
    }

    public function reverse(GoodsReceipt $goodsReceipt, ReverseGoodsReceipt $action): RedirectResponse
    {
        $action->handle($goodsReceipt);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Goods receipt reversed.')]);

        return back();
    }

    public function cancel(GoodsReceipt $goodsReceipt, CancelGoodsReceipt $action): RedirectResponse
    {
        $action->handle($goodsReceipt);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Goods receipt cancelled.')]);

        return back();
    }
}
