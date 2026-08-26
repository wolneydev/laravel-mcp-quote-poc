<?php

namespace App\Actions\Quotes;

use App\Actions\GenerateQuoteNumber;
use App\Enums\QuoteStatus;
use App\Models\Account;
use App\Models\Product;
use App\Models\Quote;
use App\Services\Quotes\QuotePricingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CreateQuoteAction
{
    public function __construct(
        private QuotePricingService $pricing,
        private GenerateQuoteNumber $generateQuoteNumber,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int|float|string}>  $items
     */
    public function handle(
        Account $sellerAccount,
        Account $customerAccount,
        array $items,
        ?string $validUntil = null,
        ?string $notes = null,
    ): Quote {
        $year = now()->year;

        return Cache::lock('quote-number:'.$year, 10)->block(5, function () use ($sellerAccount, $customerAccount, $items, $validUntil, $notes, $year): Quote {
            return DB::transaction(function () use ($sellerAccount, $customerAccount, $items, $validUntil, $notes, $year): Quote {
                $pricing = $this->pricing->calculate(
                    $this->products($items),
                    $this->quantitiesByProductId($items),
                );

                $quote = Quote::query()->create([
                    'number' => $this->generateQuoteNumber->handle($year),
                    'seller_account_id' => $sellerAccount->id,
                    'customer_account_id' => $customerAccount->id,
                    'status' => QuoteStatus::Draft,
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
