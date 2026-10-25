<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Accounting\RecordOperationalJournal;
use App\Actions\Sales\RecordCustomerBagMovement;
use App\Actions\Sales\ReverseCustomerBagMovement;
use App\Enums\CustomerBagMovementKind;
use App\Enums\CustomerBagMovementStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreCustomerBagMovementRequest;
use App\Models\Customer;
use App\Models\CustomerBagMovement;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Inventory\StockQueryService;
use App\Services\Sales\CustomerBagBalances;
use App\Support\BusinessClock;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Returnable bags lent to customers: who holds how many, and every bag given, returned or lost.
 * Recorded in one step and corrected by reversal; there is no edit. See
 * docs/INVENTORY_RULES.md §18.
 */
class CustomerBagController extends Controller
{
    public function __construct(
        private readonly CustomerBagBalances $balances,
        private readonly StockQueryService $stock,
    ) {}

    public function index(Request $request): Response
    {
        $customerId = $request->integer('customer_id') ?: null;
        $customers = Customer::query()->orderBy('name')->get(['id', 'code', 'name'])->keyBy('id');
        $products = Product::query()->where('is_returnable', true)->orderBy('code')->get(['id', 'code', 'name'])->keyBy('id');

        $search = trim((string) $request->string('search'));
        $kind = CustomerBagMovementKind::tryFrom((string) $request->string('kind'))?->value;
        $list = ListQuery::from($request)->period('movement_date')->sortable([
            'number' => 'movement_number',
            'date' => 'movement_date',
            'customer' => fn (Builder $q, string $d) => $q->orderBy(Customer::select('name')->whereColumn('customers.id', 'customer_bag_movements.customer_id'), $d),
            'kind' => 'kind',
            'quantity' => 'quantity',
            'status' => 'status',
        ], 'date');

        $movements = $list->paginate(CustomerBagMovement::query()
            ->with(['customer:id,code,name', 'product:id,code,name', 'warehouse:id,code'])
            ->when($customerId, fn ($query, int $id) => $query->where('customer_id', $id))
            ->when($kind, fn ($query, string $value) => $query->where('kind', $value))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('movement_number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%")))))
            ->through(fn (CustomerBagMovement $movement): array => $this->summarise($movement));

        return Inertia::render('sales/bags/Index', [
            'holdings' => $this->balances->balances($customerId)
                ->filter(fn (object $row): bool => $row->quantity !== '0.000')
                ->map(fn (object $row): array => [
                    'customer_id' => $row->customer_id,
                    'customer_code' => $customers->get($row->customer_id)?->code,
                    'customer_name' => $customers->get($row->customer_id)?->name,
                    'product_code' => $products->get($row->product_id)?->code,
                    'product_name' => $products->get($row->product_id)?->name,
                    'quantity' => $row->quantity,
                ])->values(),
            'movements' => $movements,
            'filters' => ['search' => $search, 'customer_id' => $customerId, 'kind' => $kind ?? ''] + $list->filters(),
            'kinds' => CustomerBagMovementKind::options(),
            'customers' => $customers->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        $products = Product::query()->where('is_returnable', true)->with('primaryUom:id,code')->orderBy('code')->get();

        return Inertia::render('sales/bags/Create', [
            'kinds' => CustomerBagMovementKind::options(),
            'kind' => CustomerBagMovementKind::tryFrom((string) $request->string('kind'))?->value ?? CustomerBagMovementKind::Given->value,
            'customerId' => $request->integer('customer_id') ?: null,
            'customers' => Customer::query()->orderBy('name')->get(['id', 'code', 'name', 'is_active']),
            'products' => $products->map(fn (Product $product): array => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'uom' => $product->primaryUom?->code,
                // What each store holds, so the form can say how many can be given.
                'stock' => $this->stock->balancesByProductAndWarehouse($product->id)
                    ->map(fn (object $row): array => ['warehouse_id' => $row->warehouse_id, 'quantity' => $row->quantity])
                    ->values(),
            ])->values(),
            // What each customer holds now, so returns and losses can be checked as they are typed.
            'holdings' => $this->balances->balances()
                ->map(fn (object $row): array => ['customer_id' => $row->customer_id, 'product_id' => $row->product_id, 'quantity' => $row->quantity])
                ->values(),
            'warehouses' => Warehouse::query()->operational()->orderByDesc('is_default')->orderBy('code')->get(['id', 'code', 'name', 'is_default']),
            'today' => BusinessClock::todayString(),
        ]);
    }

    public function store(StoreCustomerBagMovementRequest $request, RecordCustomerBagMovement $action): RedirectResponse
    {
        $movement = $action->handle($request->validated(), auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bags recorded.')]);

        return to_route('customer-bags.show', $movement);
    }

    public function show(CustomerBagMovement $customerBag, RecordOperationalJournal $accounting): Response
    {
        $customerBag->load([
            'customer:id,code,name',
            'product:id,code,name',
            'warehouse:id,code,name',
            'inventoryTransaction:id,reference',
            'reversalTransaction:id,reference',
            'createdBy:id,name',
            'reversedBy:id,name',
        ]);

        $journal = $accounting->originalJournalFor($customerBag);

        return Inertia::render('sales/bags/Show', [
            'movement' => $this->summarise($customerBag) + [
                'customer_id' => $customerBag->customer_id,
                'held_now' => $this->balances->held($customerBag->customer_id, $customerBag->product_id),
                'note' => $customerBag->note,
                'inventory_reference' => $customerBag->inventoryTransaction?->reference,
                'reversal_reference' => $customerBag->reversalTransaction?->reference,
                'journal_number' => $journal?->journal_number,
                'journal_id' => $journal?->id,
                'created_by' => $customerBag->createdBy?->name,
                'posted_at' => BusinessClock::display($customerBag->posted_at),
                'reversed_by' => $customerBag->reversedBy?->name,
                'reversed_at' => BusinessClock::display($customerBag->reversed_at),
                'can_reverse' => $customerBag->status->canReverse(),
            ],
        ]);
    }

    public function reverse(CustomerBagMovement $customerBag, ReverseCustomerBagMovement $action): RedirectResponse
    {
        $action->handle($customerBag, auth()->id());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Bag record reversed.')]);

        return to_route('customer-bags.show', $customerBag);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(CustomerBagMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'movement_number' => $movement->movement_number,
            'movement_date' => $movement->movement_date->toDateString(),
            'kind' => $movement->kind->value,
            'kind_label' => $movement->kind->label(),
            'customer_name' => $movement->customer?->name,
            'customer_code' => $movement->customer?->code,
            'product_name' => $movement->product?->name,
            'warehouse' => $movement->warehouse?->code,
            'quantity' => (string) $movement->quantity,
            'status' => $movement->status->value,
            'status_label' => $movement->status->label(),
            'is_reversed' => $movement->status === CustomerBagMovementStatus::Reversed,
        ];
    }
}
