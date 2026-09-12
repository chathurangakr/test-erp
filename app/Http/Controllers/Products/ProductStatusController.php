<?php

namespace App\Http\Controllers\Products;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Activates and deactivates a product.
 *
 * Deactivation replaces deletion: master data referenced by historic transactions must
 * remain in the system (see CLAUDE.md — audit history is never deleted).
 */
class ProductStatusController extends Controller
{
    public function update(Product $product): RedirectResponse
    {
        $product->update(['is_active' => ! $product->is_active]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $product->is_active ? __('Product activated.') : __('Product deactivated.'),
        ]);

        return back();
    }
}
