<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Sales\CancelDelivery;
use App\Actions\Sales\PostDelivery;
use App\Actions\Sales\ReverseDelivery;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Posting, reversing and cancelling deliveries.
 *
 * All inventory work happens in the action classes; this controller only translates the
 * result into a response.
 */
class DeliveryActionController extends Controller
{
    public function post(Delivery $delivery, PostDelivery $action): RedirectResponse
    {
        $action->handle($delivery);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Delivery posted to inventory.')]);

        return back();
    }

    public function reverse(Delivery $delivery, ReverseDelivery $action): RedirectResponse
    {
        $action->handle($delivery);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Delivery reversed.')]);

        return back();
    }

    public function cancel(Delivery $delivery, CancelDelivery $action): RedirectResponse
    {
        $action->handle($delivery);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Delivery cancelled.')]);

        return back();
    }
}
