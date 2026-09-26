<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeliveryStatus;
use App\Enums\GoodsReceiptStatus;
use App\Enums\Permission;
use App\Enums\ProductionStatus;
use App\Enums\ProductionType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Accounting\AccountsPayableService;
use App\Services\Accounting\AccountsReceivableService;
use App\Services\Accounting\CashBankBookService;
use App\Services\Accounting\FinanceDashboardService;
use App\Services\Accounting\InventoryValuationReportService;
use App\Services\Inventory\StockQueryService;
use App\Support\DecimalMath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A home screen tailored to what the user may see — decided HERE, on the server.
 *
 * Each section is built only when the user holds the permission behind it. A storekeeper's
 * response contains no finance figures at all; it is not enough for the app to hide them.
 * Every figure comes from the service that owns it, exactly as the web dashboard does.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly StockQueryService $stock,
        private readonly InventoryValuationReportService $valuation,
        private readonly FinanceDashboardService $finance,
        private readonly AccountsReceivableService $receivables,
        private readonly AccountsPayableService $payables,
        private readonly CashBankBookService $cashBank,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $sections = [];

        if ($user->hasPermission(Permission::InventoryView)) {
            $positions = $this->stock->balancesByProductAndWarehouse()
                ->filter(fn ($row): bool => DecimalMath::isPositive((string) $row->quantity));

            $sections['inventory'] = [
                'stock_positions' => $positions->count(),
                'products_in_stock' => $positions->pluck('product_id')->unique()->count(),
            ];

            if ($user->hasPermission(Permission::ReportsInventory)) {
                $sections['inventory']['inventory_value'] = $this->valuation->report()['value_total'];
            }
        }

        if ($user->hasPermission(Permission::PurchasingView)) {
            $sections['purchasing'] = [
                'draft_purchase_orders' => PurchaseOrder::query()->where('status', PurchaseOrderStatus::Draft)->count(),
                'open_purchase_orders' => PurchaseOrder::query()
                    ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::PartiallyReceived])
                    ->count(),
                'draft_goods_receipts' => GoodsReceipt::query()->where('status', GoodsReceiptStatus::Draft)->count(),
            ];
        }

        if ($user->hasPermission(Permission::SalesView)) {
            $sections['sales'] = [
                'draft_sales_orders' => SalesOrder::query()->where('status', SalesOrderStatus::Draft)->count(),
                'open_sales_orders' => SalesOrder::query()
                    ->whereIn('status', [SalesOrderStatus::Approved, SalesOrderStatus::PartiallyDelivered])
                    ->count(),
                'draft_deliveries' => Delivery::query()->where('status', DeliveryStatus::Draft)->count(),
            ];
        }

        if ($user->hasPermission(Permission::ProductionView)) {
            $sections['production'] = collect(ProductionType::cases())->mapWithKeys(fn (ProductionType $type): array => [
                strtolower($type->value) => [
                    'draft' => ProductionOrder::query()->where('production_type', $type)->where('status', ProductionStatus::Draft)->count(),
                ],
            ])->all();
        }

        if ($user->hasPermission(Permission::ReportsManagement)) {
            $window = $this->finance->defaultWindow();
            $summary = $this->finance->summary(now()->parse($window['from']), now()->parse($window['to']));

            $sections['finance'] = [
                'period' => $summary['period'],
                'performance' => $summary['performance'],
            ];
        }

        if ($user->hasPermission(Permission::ArView)) {
            $sections['receivables'] = ['outstanding' => $this->receivables->totalOutstanding()];
        }

        if ($user->hasPermission(Permission::ApView)) {
            $sections['payables'] = ['outstanding' => $this->payables->totalOutstanding()];
        }

        if ($user->hasPermission(Permission::CashView)) {
            $cash = $this->cashBank->summary();

            $sections['cash_bank'] = [
                'cash_total' => $cash['cash_total'],
                'bank_total' => $cash['bank_total'],
                'combined_total' => $cash['combined_total'],
            ];
        }

        return response()->json([
            'data' => [
                'currency' => config('accounting.base_currency'),
                'sections' => (object) $sections,
            ],
        ]);
    }
}
