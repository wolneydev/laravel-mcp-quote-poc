<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_seeds_products_idempotently_by_code(): void
    {
        $this->seed(ProductSeeder::class);
        $this->seed(ProductSeeder::class);

        $this->assertSame(5, Product::query()->count());
        $this->assertSame(5, Product::query()->distinct()->count('code'));
    }

    public function test_it_updates_existing_rows_with_matching_codes(): void
    {
        $this->seed(ProductSeeder::class);

        Product::query()->where('code', 'PROD-000001')->update([
            'name' => 'Outdated Access Doors',
            'price' => 1.00,
        ]);

        $this->seed(ProductSeeder::class);

        $product = Product::query()->where('code', 'PROD-000001')->first();

        $this->assertNotNull($product);
        $this->assertSame('Access Doors and Panels', $product->name);
        $this->assertSame('450.00', $product->price);
        $this->assertSame(5, Product::query()->count());
    }
}
