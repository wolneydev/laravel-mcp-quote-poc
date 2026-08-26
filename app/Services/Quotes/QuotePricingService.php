<?php

namespace App\Services\Quotes;

use App\Models\Product;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class QuotePricingService
{
    private const SCALE = 2;

    private const MINOR_UNITS = 100;

    /**
     * @param  Collection<int, Product>  $products
     * @param  array<int, int|float|string>  $quantitiesByProductId
     */
    public function calculate(Collection $products, array $quantitiesByProductId): QuotePricingResult
    {
        if ($products->isEmpty()) {
            throw new InvalidArgumentException('A quote must contain at least one product.');
        }

        $currencies = $products
            ->map(fn (Product $product): string => strtoupper((string) $product->currency))
            ->unique()
            ->values();

        if ($currencies->count() !== 1) {
            throw new InvalidArgumentException('All products in a quote must use the same currency.');
        }

        $items = [];
        $totalMinorUnits = 0;

        foreach ($products as $product) {
            if (! array_key_exists($product->id, $quantitiesByProductId)) {
                throw new InvalidArgumentException("Missing quantity for product [{$product->id}].");
            }

            $quantity = $this->decimal((string) $quantitiesByProductId[$product->id]);
            $quantityMinorUnits = $this->toMinorUnits($quantity);

            if ($quantityMinorUnits <= 0) {
                throw new InvalidArgumentException('Quantity must be greater than zero.');
            }

            $unitPrice = $this->decimal((string) $product->price);
            $lineTotalMinorUnits = $this->multiplyMinorUnits($quantityMinorUnits, $this->toMinorUnits($unitPrice));
            $totalMinorUnits += $lineTotalMinorUnits;

            $items[] = [
                'product_id' => $product->id,
                'product_code' => $product->code,
                'product_name' => $product->name,
                'unit' => $product->unit,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $this->fromMinorUnits($lineTotalMinorUnits),
            ];
        }

        return new QuotePricingResult(
            currency: $currencies->first(),
            total: $this->fromMinorUnits($totalMinorUnits),
            items: $items,
        );
    }

    private function decimal(string $value): string
    {
        return $this->fromMinorUnits($this->toMinorUnits($value));
    }

    private function toMinorUnits(string $value): int
    {
        $negative = str_starts_with($value, '-');
        $unsigned = ltrim($value, '+-');

        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');

        $minorUnits = (int) ($whole.$fraction);

        return $negative ? -$minorUnits : $minorUnits;
    }

    private function fromMinorUnits(int $minorUnits): string
    {
        $negative = $minorUnits < 0;
        $absolute = abs($minorUnits);

        return sprintf(
            '%s%d.%02d',
            $negative ? '-' : '',
            intdiv($absolute, self::MINOR_UNITS),
            $absolute % self::MINOR_UNITS,
        );
    }

    private function multiplyMinorUnits(int $left, int $right): int
    {
        return (int) round(($left * $right) / self::MINOR_UNITS);
    }
}
