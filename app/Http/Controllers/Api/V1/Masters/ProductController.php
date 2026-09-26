<?php

namespace App\Http\Controllers\Api\V1\Masters;

use App\Http\Controllers\Api\V1\Concerns\PaginatesResults;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Resources\V1\Masters\ProductResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Products over the API — the same validation as the website. No delete: a product is
 * deactivated, never removed, because historic transactions refer to it.
 */
class ProductController extends Controller
{
    use PaginatesResults;

    private const WITH = ['primaryUom:id,code,name', 'secondaryUom:id,code,name'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $status = $request->string('status')->toString();

        $products = Product::query()
            ->with(self::WITH)
            ->search((string) $request->string('search'))
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('code')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return ProductResource::collection($products);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load(self::WITH));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return (new ProductResource($product->refresh()->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return new ProductResource($product->refresh()->load(self::WITH));
    }

    /**
     * Sets the active flag to the state requested. Unlike the website's toggle, the API takes
     * the target state explicitly, so a retried request cannot flip it back.
     */
    public function status(Request $request, Product $product): ProductResource
    {
        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        $product->update(['is_active' => (bool) $validated['is_active']]);

        return new ProductResource($product->refresh()->load(self::WITH));
    }
}
