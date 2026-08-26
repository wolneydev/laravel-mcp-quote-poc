<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $normalizedDocument = Customer::normalizeDocument($request->string('document')->value());
        $normalizedEmail = Customer::normalizeEmail($request->string('email')->value());

        $customers = Customer::query()
            ->when(
                $request->filled('search'),
                fn (Builder $query) => $query->search($request->string('search')->trim()->value()),
            )
            ->when(
                $request->filled('code'),
                fn (Builder $query) => $query->where('code', $request->string('code')->trim()->value()),
            )
            ->when(
                $normalizedDocument !== null,
                fn (Builder $query) => $query->where('document', $normalizedDocument),
            )
            ->when(
                $normalizedEmail !== null,
                fn (Builder $query) => $query->where('email', $normalizedEmail),
            )
            ->when(
                $request->has('active'),
                fn (Builder $query) => $query->where('active', $request->boolean('active')),
            )
            ->latest('id')
            ->paginate();

        return CustomerResource::collection($customers);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = Customer::create($request->validated());

        return CustomerResource::make($customer)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        return CustomerResource::make($customer);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validated());

        return CustomerResource::make($customer);
    }

    public function destroy(Customer $customer): CustomerResource
    {
        $customer->update(['active' => false]);

        return CustomerResource::make($customer->refresh());
    }
}
