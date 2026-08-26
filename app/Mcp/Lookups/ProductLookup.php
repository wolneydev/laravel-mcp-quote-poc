<?php

namespace App\Mcp\Lookups;

use App\Mcp\Exceptions\QuoteLookupException;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ProductLookup
{
    public const MAX_RESULTS = 10;

    /**
     * @return Collection<int, Product>
     */
    public function search(string $query, bool $activeOnly = true, int $limit = self::MAX_RESULTS): Collection
    {
        $term = trim($query);
        $limit = min(max($limit, 1), self::MAX_RESULTS);

        return $this->searchQuery($term, $activeOnly)
            ->orderByRaw('CASE WHEN code = ? THEN 0 WHEN code LIKE ? THEN 1 ELSE 2 END', [$term, '%'.$this->escapeLike($term).'%'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function resolve(string $identifier): Product
    {
        $term = trim($identifier);

        $exact = Product::query()->where('code', $term)->first();

        if ($exact !== null) {
            if ($exact->active !== true) {
                throw new QuoteLookupException(
                    'inactive_product',
                    "Product [{$term}] is inactive.",
                    field: 'product',
                );
            }

            return $exact;
        }

        $matches = Product::query()
            ->where('active', true)
            ->where('name', 'like', '%'.$this->escapeLike($term).'%')
            ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$term])
            ->latest('id')
            ->limit(self::MAX_RESULTS + 1)
            ->get();

        if ($matches->count() === 1) {
            return $matches->first();
        }

        if ($matches->isEmpty()) {
            throw new QuoteLookupException(
                'not_found',
                "Product [{$term}] was not found.",
                field: 'product',
            );
        }

        throw new QuoteLookupException(
            'ambiguous_match',
            "Product [{$term}] matches more than one product.",
            $matches->take(self::MAX_RESULTS)->map(fn (Product $product): array => $this->summary($product))->values()->all(),
            'product',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Product $product): array
    {
        return [
            'id' => $product->id,
            'code' => $product->code,
            'name' => $product->name,
            'unit' => $product->unit,
            'price' => $product->price,
            'currency' => $product->currency,
            'active' => $product->active,
        ];
    }

    private function searchQuery(string $term, bool $activeOnly): Builder
    {
        $like = '%'.$this->escapeLike($term).'%';

        return Product::query()
            ->when($activeOnly, fn (Builder $query): Builder => $query->where('active', true))
            ->where(function (Builder $query) use ($term, $like): void {
                $query->where('code', $term)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('name', 'like', $like);
            });
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
