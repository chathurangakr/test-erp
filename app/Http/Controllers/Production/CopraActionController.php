<?php

namespace App\Http\Controllers\Production;

use App\Actions\Production\CancelProduction;
use App\Actions\Production\PostCopraProduction;
use App\Actions\Production\ReverseProduction;
use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Posting, reversing and cancelling copra production.
 *
 * All inventory and costing work happens in the action classes — the same reversal and
 * cancellation actions dehusking and husk chipping use.
 */
class CopraActionController extends Controller
{
    public function post(ProductionOrder $copra, PostCopraProduction $action): RedirectResponse
    {
        $action->handle($copra);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Production posted to inventory.')]);

        return back();
    }

    public function reverse(ProductionOrder $copra, ReverseProduction $action): RedirectResponse
    {
        $action->handle($copra);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Production reversed.')]);

        return back();
    }

    public function cancel(ProductionOrder $copra, CancelProduction $action): RedirectResponse
    {
        $action->handle($copra);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Production cancelled.')]);

        return back();
    }
}
