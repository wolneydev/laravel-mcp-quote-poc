<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Quotes\QuotePricingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class QuotePricingServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_calculates_a_single_item_quote(): void
    {
        $product = Product::factory()->create([
            'code' => 'PROD-000001',
            'name' => 'Mechanical Keyboard',
            'unit' => 'unit',
            'price' => '450.50',
            'currency' => 'CAD',
        ]);

        $result = app(QuotePricingService::class)->calculate(
            collect([$product]),
            [$product->id => 3],
        );

        $this->assertSame('CAD', $result->currency);
        $this->assertSame('1351.50', $result->total);
        $this->assertCount(1, $result->items);
        $this->assertSame($product->id, $result->items[0]['product_id']);
        $this->assertSame('PROD-000001', $result->items[0]['product_code']);
        $this->assertSame('Mechanical Keyboard', $result->items[0]['product_name']);
        $this->assertSame('unit', $result->items[0]['unit']);
        $this->assertSame('3.00', $result->items[0]['quantity']);
        $this->assertSame('450.50', $result->items[0]['unit_price']);
        $this->assertSame('1351.50', $result->items[0]['line_total']);
    }

    public function test_it_calculates_a_multi_item_quote_with_decimal_precision(): void
    {
        $keyboard = Product::factory()->create([
            'price' => '10.25',
            'currency' => 'CAD',
        ]);
        $mouse = Product::factory()->create([
            'price' => '5.10',
            'currency' => 'CAD',
        ]);

        $result = app(QuotePricingService::class)->calculate(
            collect([$keyboard, $mouse]),
            [
                $keyboard->id => '2.00',
                $mouse->id => '3.00',
            ],
        );

        $this->assertSame('20.50', $result->items[0]['line_total']);
        $this->assertSame('15.30', $result->items[1]['line_total']);
        $this->assertSame('35.80', $result->total);
    }

    public function test_it_rejects_mixed_currencies(): void
    {
        $cad = Product::factory()->create(['currency' => 'CAD']);
        $usd = Product::factory()->create(['currency' => 'USD']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All products in a quote must use the same currency.');

        app(QuotePricingService::class)->calculate(
            collect([$cad, $usd]),
            [
                $cad->id => 1,
                $usd->id => 1,
            ],
        );
    }
}
