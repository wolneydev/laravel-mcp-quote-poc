<?php

namespace App\Http\Controllers\Api;

use App\Actions\GenerateAccountCode;
use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = Account::query()
            ->when(
                $request->filled('search'),
                fn (Builder $query) => $query->search($request->string('search')->trim()->value()),
            )
            ->when(
                $request->filled('code'),
                fn (Builder $query) => $query->where('code', $request->string('code')->trim()->value()),
            )
            ->when(
                $request->filled('type'),
                fn (Builder $query) => $query->where('type', $request->string('type')->trim()->value()),
            )
            ->when(
                $request->filled('customer_id'),
                fn (Builder $query) => $query->where('customer_id', $request->integer('customer_id')),
            )
            ->when(
                $request->has('active'),
                fn (Builder $query) => $query->where('active', $request->boolean('active')),
            )
            ->latest('id')
            ->paginate();

        return AccountResource::collection($accounts);
    }

    public function store(StoreAccountRequest $request, GenerateAccountCode $generateAccountCode): JsonResponse
    {
        $account = Account::create([
            ...$request->validated(),
            'code' => $generateAccountCode->handle(AccountType::from($request->validated('type'))),
        ]);

        return AccountResource::make($account)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Account $account): AccountResource
    {
        return AccountResource::make($account);
    }

    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        $account->update($request->validated());

        return AccountResource::make($account);
    }

    public function destroy(Account $account): AccountResource
    {
        $account->update(['active' => false]);

        return AccountResource::make($account->refresh());
    }
}
