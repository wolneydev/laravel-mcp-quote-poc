<?php

namespace App\Actions\Quotes;

use App\Enums\QuoteStatus;
use App\Models\Account;
use App\Models\Product;
use App\Models\Quote;
use App\Services\Quotes\QuotePricingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateQuoteAction
{
    public function __construct(private QuotePricingService $pricing) {}

    /**
     * @param  list<array{product_id: int, quantity: int|float|string}>  $items
     */
    public function handle(
        Quote $quote,
        Account $sellerAccount,
        Account $customerAccount,
        array $items,
        ?string $validUntil = null,
        ?string $notes = null,
    ): Quote {
        if ($quote->status !== QuoteStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft quotes can change products, quantities, or values.',
            ]);
        }

        return DB::transaction(function () use ($quote, $sellerAccount, $customerAccount, $items, $validUntil, $notes): Quote {
            $pricing = $this->pricing->calculate(
                $this->products($items),
                $this->quantitiesByProductId($items),
            );

            $quote->items()->delete();

            $quote->update([
                'seller_account_id' => $sellerAccount->id,
                'customer_account_id' => $customerAccount->id,
                'currency' => $pricing->currency,
                'total' => $pricing->total,
                'valid_until' => $validUntil,
                'notes' => $notes,
            ]);

            foreach ($pricing->items as $item) {
                $quote->items()->create($item);
            }

            return $quote->fresh()
                ->load([
                    'sellerAccount',
                    'customerAccount.customer',
                    'items',
                ]);
        });
    }

    /**
     * @param  list<array{product_id: int, quantity: int|float|string}>  $items
     * @return Collection<int, Product>
     */
    private function products(array $items): Collection
    {
        $productIds = array_values(array_unique(array_map(
            fn (array $item): int => (int) $item['product_id'],
            $items,
        )));

        return Product::query()
            ->whereIn('id', $productIds)
            ->get()
            ->sortBy(fn (Product $product): int => array_search($product->id, $productIds, true))
            ->values();
    }

    /**
     * @param  list<array{product_id: int, quantity: int|float|string}>  $items
     * @return array<int, int|float|string>
     */
    private function quantitiesByProductId(array $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            $quantities[(int) $item['product_id']] = $item['quantity'];
        }

        return $quantities;
    }
}
