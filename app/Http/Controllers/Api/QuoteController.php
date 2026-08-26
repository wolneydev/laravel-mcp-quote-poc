<?php

namespace App\Http\Controllers\Api;

use App\Actions\Quotes\ApproveQuoteAction;
use App\Actions\Quotes\CreateQuoteAction;
use App\Actions\Quotes\RejectQuoteAction;
use App\Actions\Quotes\SubmitQuoteAction;
use App\Actions\Quotes\UpdateQuoteAction;
use App\Enums\AccountType;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQuoteRequest;
use App\Http\Requests\UpdateQuoteRequest;
use App\Http\Resources\QuoteResource;
use App\Models\Account;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class QuoteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Quote::class);

        $account = $request->user();

        $quotes = Quote::query()
            ->withDetails()
            ->when(
                $account instanceof Account && $account->type === AccountType::Seller,
                fn ($query) => $query->where('seller_account_id', $account->id),
            )
            ->when(
                $account instanceof Account && $account->type === AccountType::Customer,
                fn ($query) => $query->where('customer_account_id', $account->id),
            )
            ->latest('id')
            ->paginate();

        return QuoteResource::collection($quotes);
    }

    public function store(StoreQuoteRequest $request, CreateQuoteAction $createQuote): JsonResponse
    {
        $quote = $createQuote->handle(
            sellerAccount: Account::query()->findOrFail($request->validated('seller_account_id')),
            customerAccount: Account::query()->findOrFail($request->validated('customer_account_id')),
            items: $request->validated('items'),
            validUntil: $request->validated('valid_until'),
            notes: $request->validated('notes'),
        );

        return QuoteResource::make($quote)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Quote $quote): QuoteResource
    {
        Gate::authorize('view', $quote);

        return QuoteResource::make($quote->load([
            'sellerAccount',
            'customerAccount.customer',
            'items',
        ]));
    }

    public function update(UpdateQuoteRequest $request, Quote $quote, UpdateQuoteAction $updateQuote): QuoteResource
    {
        $quote = $updateQuote->handle(
            quote: $quote,
            sellerAccount: Account::query()->findOrFail($request->validated('seller_account_id')),
            customerAccount: Account::query()->findOrFail($request->validated('customer_account_id')),
            items: $request->validated('items'),
            validUntil: $request->validated('valid_until'),
            notes: $request->validated('notes'),
        );

        return QuoteResource::make($quote);
    }

    public function destroy(Quote $quote): QuoteResource
    {
        Gate::authorize('delete', $quote);

        if ($quote->status !== QuoteStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft quotes can be deleted.',
            ]);
        }

        $quote->load([
            'sellerAccount',
            'customerAccount.customer',
            'items',
        ]);

        $quote->delete();

        return QuoteResource::make($quote);
    }

    public function submit(Quote $quote, SubmitQuoteAction $submitQuote): QuoteResource
    {
        Gate::authorize('submit', $quote);

        return QuoteResource::make($submitQuote->handle($quote));
    }

    public function approve(Quote $quote, ApproveQuoteAction $approveQuote): QuoteResource
    {
        Gate::authorize('approve', $quote);

        return QuoteResource::make($approveQuote->handle($quote));
    }

    public function reject(Quote $quote, RejectQuoteAction $rejectQuote): QuoteResource
    {
        Gate::authorize('reject', $quote);

        return QuoteResource::make($rejectQuote->handle($quote));
    }
}
