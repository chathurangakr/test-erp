<?php

namespace App\Http\Controllers\Cash;

use App\Actions\Cash\CancelFundTransfer;
use App\Actions\Cash\PostFundTransfer;
use App\Actions\Cash\ReverseFundTransfer;
use App\Http\Controllers\Controller;
use App\Models\FundTransfer;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The fund transfer lifecycle: post, reverse, cancel.
 */
class FundTransferActionController extends Controller
{
    public function post(FundTransfer $fundTransfer, PostFundTransfer $action): RedirectResponse
    {
        $action->handle($fundTransfer, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fund transfer posted.')]);

        return to_route('fund-transfers.show', $fundTransfer);
    }

    public function reverse(FundTransfer $fundTransfer, ReverseFundTransfer $action): RedirectResponse
    {
        $action->handle($fundTransfer, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Fund transfer reversed.')]);

        return to_route('fund-transfers.show', $fundTransfer);
    }

    public function cancel(FundTransfer $fundTransfer, CancelFundTransfer $action): RedirectResponse
    {
        $action->handle($fundTransfer, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Draft fund transfer cancelled.')]);

        return to_route('fund-transfers.show', $fundTransfer);
    }
}
