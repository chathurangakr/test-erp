<?php

namespace App\Http\Controllers\Production;

use App\Actions\Production\CancelProduction;
use App\Actions\Production\PostHuskChippingProduction;
use App\Actions\Production\ReverseProduction;
use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Posting, reversing and cancelling husk chipping production.
 *
 * All inventory and costing work happens in the action classes — the same reversal and
 * cancellation actions dehusking uses.
 */
class HuskChippingActionController extends Controller
{
    public function post(ProductionOrder $huskChipping, PostHuskChippingProduction $action): RedirectResponse
    {
        $action->handle($huskChipping);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Production posted to inventory.')]);

        return back();
    }

    public function reverse(ProductionOrder $huskChipping, ReverseProduction $action): RedirectResponse
    {
        $action->handle($huskChipping);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Production reversed.')]);

        return back();
    }

    public function cancel(ProductionOrder $huskChipping, CancelProduction $action): RedirectResponse
    {
        $action->handle($huskChipping);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Production cancelled.')]);

        return back();
    }
}
