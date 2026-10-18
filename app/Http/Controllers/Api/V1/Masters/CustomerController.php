<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Actions\Masters\CreateCustomer;
use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\V1\Masters\CustomerResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Customers over the API — the same validation as the website. No delete: a customer is
 * deactivated, never removed, because historic documents refer to it.
 */
class CustomerController extends Controller
{
    use PaginatesResults;

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString();

        $customers = Customer::query()
            ->search((string) $request->string('search'))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('code')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return CustomerResource::collection($customers);
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer);
    }

    public function store(StoreCustomerRequest $request, CreateCustomer $createCustomer): JsonResponse
    {
        $customer = $createCustomer->handle($request->validated());

        return (new CustomerResource($customer->refresh()))->response()->setStatusCode(201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return new CustomerResource($customer->refresh());
    }

    /**
     * Sets the active flag to the state requested. Unlike the website's toggle, the API takes
     * the target state explicitly, so a retried request cannot flip it back.
     */
    public function status(Request $request, Customer $customer): CustomerResource
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $customer->update(['is_active' => (bool) $validated['is_active']]);

        return new CustomerResource($customer->refresh());
    }
}
