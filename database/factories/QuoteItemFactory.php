<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuoteItem>
 */
class QuoteItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = '1.00';
        $unitPrice = '10.00';

        return [
            'quote_id' => Quote::factory(),
            'product_id' => Product::factory(),
            'product_code' => 'PROD-000001',
            'product_name' => 'Sample Product',
            'unit' => 'unit',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => '10.00',
        ];
    }
}
