<?php

namespace App\Http\Controllers\Assets;

use App\Actions\Assets\RecordAssetMaintenance;
use App\Actions\Assets\RecordFixedAsset;
use App\Actions\Assets\ReverseAssetMaintenance;
use App\Actions\Assets\ReverseAssetSale;
use App\Actions\Assets\ReverseFixedAsset;
use App\Actions\Assets\SellFixedAsset;
use App\Enums\AssetCategory;
use App\Enums\AssetFunding;
use App\Enums\FixedAssetStatus;
use App\Enums\LoanDocumentStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Requests\Assets\SellFixedAssetRequest;
use App\Http\Requests\Assets\StoreAssetMaintenanceRequest;
use App\Http\Requests\Assets\StoreFixedAssetRequest;
use App\Http\Requests\Assets\UpdateFixedAssetRequest;
use App\Http\Requests\Payroll\PayPayrollRequest;
use App\Models\AssetMaintenance;
use App\Models\FixedAsset;
use App\Services\Accounting\CashDocumentOptions;
use App\Services\Loans\LoanBalances;
use App\Support\BusinessClock;
use App\Support\DecimalMath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fixed assets: what the factory owns, what each cost, how it was paid for, its repair history
 * and its sale. Purchase figures are corrected by reversal; only the name, kind and description
 * are edited. See docs/FIXED_ASSETS.md.
 */
class FixedAssetController extends Controller
{
    public function __construct(private readonly CashDocumentOptions $options) {}

    public function index(Request $request): Response
    {
        $showAll = $request->boolean('all');

        $assets = FixedAsset::query()
            ->withSum(['maintenances as maintenance_cost' => fn ($query) => $query->where('status', LoanDocumentStatus::Posted->value)], 'cost')
            ->when(! $showAll, fn ($query) => $query->where('status', FixedAssetStatus::InUse->value))
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (FixedAsset $asset): array => $this->summarise($asset) + [
                'maintenance_cost' => DecimalMath::money(DecimalMath::of((string) ($asset->maintenance_cost ?? '0'))),
            ]);

        return Inertia::render('assets/Index', [
            'assets' => $assets,
            'showAll' => $showAll,
            'totalInUse' => DecimalMath::money($assets->where('status', FixedAssetStatus::InUse->value)
                ->reduce(fn (string $sum, array $asset): string => DecimalMath::add($sum, $asset['purchase_price']), DecimalMath::zero())),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('assets/Create', [
            'categories' => AssetCategory::options(),
            'fundings' => AssetFunding::options(),
            'accounts' => $this->options->cashBankAccounts(),
            'paymentMethods' => PayPayrollRequest::paymentMethodOptions(),
            'today' => BusinessClock::todayString(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function store(StoreFixedAssetRequest $request, RecordFixedAsset $action): RedirectResponse
    {
        $asset = $action->handle($request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Asset added.')]);

        return to_route('assets.show', $asset);
    }

    public function show(FixedAsset $asset, LoanBalances $balances): Response
    {
        $asset->load([
            'loan', 'cashBankAccount:id,display_name', 'saleCashBankAccount:id,display_name',
            'journalEntry.lines.account:id,code,name', 'saleJournalEntry.lines.account:id,code,name',
            'createdBy:id,name', 'reversedBy:id,name', 'maintenances.cashBankAccount:id,display_name',
        ]);
        $standing = $asset->maintenances->where('status', LoanDocumentStatus::Posted);

        return Inertia::render('assets/Show', [
            'asset' => $this->summarise($asset) + [
                'description' => $asset->description,
                'down_payment' => (string) $asset->down_payment,
                'paid_from' => $asset->cashBankAccount?->display_name,
                'loan' => $asset->loan === null ? null : [
                    'id' => $asset->loan->id,
                    'loan_number' => $asset->loan->loan_number,
                    'lender' => $asset->loan->lender,
                    'amount' => (string) $asset->loan->amount,
                    'outstanding' => $balances->outstanding($asset->loan),
                ],
                'sale' => $asset->sold_date === null ? null : [
                    'sold_date' => $asset->sold_date->toDateString(),
                    'sale_price' => (string) $asset->sale_price,
                    'buyer' => $asset->buyer,
                    'paid_into' => $asset->saleCashBankAccount?->display_name,
                    'gain' => DecimalMath::money(DecimalMath::sub((string) $asset->sale_price, (string) $asset->purchase_price)),
                    'journal' => PayrollController::journal($asset->saleJournalEntry),
                ],
                'maintenance_cost' => DecimalMath::money($standing->reduce(fn (string $sum, AssetMaintenance $m): string => DecimalMath::add($sum, (string) $m->cost), DecimalMath::zero())),
                'maintenances' => $asset->maintenances->sortByDesc('maintenance_date')->values()->map(fn (AssetMaintenance $m): array => [
                    'id' => $m->id,
                    'maintenance_number' => $m->maintenance_number,
                    'maintenance_date' => $m->maintenance_date->toDateString(),
                    'work_done' => $m->work_done,
                    'done_by' => $m->done_by,
                    'cost' => (string) $m->cost,
                    'paid_from' => $m->cashBankAccount?->display_name,
                    'is_reversed' => $m->status === LoanDocumentStatus::Reversed,
                    'status_label' => $m->status->label(),
                ]),
                'journal' => PayrollController::journal($asset->journalEntry),
                'created_by' => $asset->createdBy?->name,
                'posted_at' => BusinessClock::display($asset->posted_at),
                'reversed_by' => $asset->reversedBy?->name,
                'reversed_at' => BusinessClock::display($asset->reversed_at),
            ],
            'accounts' => $this->options->cashBankAccounts(),
            'paymentMethods' => PayPayrollRequest::paymentMethodOptions(),
            'today' => BusinessClock::todayString(),
            'currency' => config('accounting.base_currency'),
        ]);
    }

    public function edit(FixedAsset $asset): Response
    {
        return Inertia::render('assets/Edit', [
            'asset' => ['id' => $asset->id, 'asset_number' => $asset->asset_number, 'name' => $asset->name, 'category' => $asset->category->value, 'description' => $asset->description],
            'categories' => AssetCategory::options(),
        ]);
    }

    public function update(UpdateFixedAssetRequest $request, FixedAsset $asset): RedirectResponse
    {
        $asset->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Asset updated.')]);

        return to_route('assets.show', $asset);
    }

    public function reverse(FixedAsset $asset, ReverseFixedAsset $action): RedirectResponse
    {
        $action->handle($asset, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Asset reversed.')]);

        return to_route('assets.show', $asset);
    }

    public function sell(SellFixedAssetRequest $request, FixedAsset $asset, SellFixedAsset $action): RedirectResponse
    {
        $action->handle($asset, $request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sale recorded.')]);

        return to_route('assets.show', $asset);
    }

    public function reverseSale(FixedAsset $asset, ReverseAssetSale $action): RedirectResponse
    {
        $action->handle($asset, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sale reversed. The asset is back in use.')]);

        return to_route('assets.show', $asset);
    }

    public function storeMaintenance(StoreAssetMaintenanceRequest $request, FixedAsset $asset, RecordAssetMaintenance $action): RedirectResponse
    {
        $action->handle($asset, $request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Maintenance recorded.')]);

        return to_route('assets.show', $asset);
    }

    public function reverseMaintenance(AssetMaintenance $assetMaintenance, ReverseAssetMaintenance $action): RedirectResponse
    {
        $action->handle($assetMaintenance, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Maintenance reversed.')]);

        return to_route('assets.show', $assetMaintenance->fixed_asset_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(FixedAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'asset_number' => $asset->asset_number,
            'name' => $asset->name,
            'category' => $asset->category->value,
            'category_label' => $asset->category->label(),
            'purchase_date' => $asset->purchase_date->toDateString(),
            'purchase_price' => (string) $asset->purchase_price,
            'funding' => $asset->funding->value,
            'funding_label' => $asset->funding->label(),
            'status' => $asset->status->value,
            'status_label' => $asset->status->label(),
        ];
    }
}
