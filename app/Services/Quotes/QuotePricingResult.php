<?php

namespace App\Services\Quotes;

final readonly class QuotePricingResult
{
    /**
     * @param  list<array{
     *     product_id: int,
     *     product_code: string,
     *     product_name: string,
     *     unit: string,
     *     quantity: string,
     *     unit_price: string,
     *     line_total: string
     * }>  $items
     */
    public function __construct(
        public string $currency,
        public string $total,
        public array $items,
    ) {}
}
