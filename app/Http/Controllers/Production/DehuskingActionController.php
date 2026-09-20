<?php

namespace App\Http\Controllers\Production;

use App\Actions\Production\CancelProduction;
use App\Actions\Production\PostDehuskingProduction;
use App\Actions\Production\ReverseProduction;
use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Posting, reversing and cancelling dehusking production.
 *
 * All inventory and costing work happens in the action classes.
 */
class DehuskingActionController extends Controller
{
    public function post(ProductionOrder $dehusking, PostDehuskingProduction $action): RedirectResponse
    {
        $action->handle($dehusking);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Production posted to inventory.')]);

        return back();
    }

    public function reverse(ProductionOrder $dehusking, ReverseProduction $action): RedirectResponse
    {
        $action->handle($dehusking);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Production reversed.')]);

        return back();
    }

    public function cancel(ProductionOrder $dehusking, CancelProduction $action): RedirectResponse
    {
        $action->handle($dehusking);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Production cancelled.')]);

        return back();
    }
}
