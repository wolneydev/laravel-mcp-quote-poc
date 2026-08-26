<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = Product::query()
            ->when(
                $request->filled('search'),
                fn (Builder $query) => $query->search($request->string('search')->trim()->value()),
            )
            ->when(
                $request->filled('code'),
                fn (Builder $query) => $query->where('code', $request->string('code')->trim()->value()),
            )
            ->when(
                $request->has('active'),
                fn (Builder $query) => $query->where('active', $request->boolean('active')),
            )
            ->latest('id')
            ->paginate();

        return ProductResource::collection($products);
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::create($request->validated());

        return ProductResource::make($product)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Product $product): ProductResource
    {
        return ProductResource::make($product);
    }

    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $product->update($request->validated());

        return ProductResource::make($product);
    }

    public function destroy(Product $product): ProductResource
    {
        $product->update(['active' => false]);

        return ProductResource::make($product->refresh());
    }
}
