<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Accounting\ProductProfitabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Operational product profitability — read only.
 *
 * Built from posted deliveries, not the general ledger, and labelled as such everywhere so it
 * is never mistaken for the financial profit and loss statement.
 */
class ProductProfitabilityController extends Controller
{
    public function __construct(private readonly ProductProfitabilityService $profitability) {}

    public function index(Request $request): Response
    {
        $from = $request->date('from');
        $to = $request->date('to');
        $productId = $request->integer('product_id') ?: null;
        $customerId = $request->integer('customer_id') ?: null;

        return Inertia::render('finance/reports/ProductProfitability', [
            'report' => $this->profitability->report(
                $from === null ? null : Carbon::parse($from),
                $to === null ? null : Carbon::parse($to),
                $productId,
                $customerId,
            ),
            'deliveries' => $productId === null ? [] : $this->profitability->deliveriesFor(
                $productId,
                $from === null ? null : Carbon::parse($from),
                $to === null ? null : Carbon::parse($to),
                $customerId,
            ),
            'products' => Product::query()->orderBy('code')->get(['id', 'code', 'name']),
            'customers' => Customer::query()->orderBy('code')->get(['id', 'code', 'name']),
            'filters' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'product_id' => $productId,
                'customer_id' => $customerId,
            ],
            'currency' => config('accounting.base_currency'),
        ]);
    }
}
