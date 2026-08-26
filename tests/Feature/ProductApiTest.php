<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_creates_a_product(): void
    {
        $response = $this->postJson('/api/products', $this->productPayload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.code', 'PROD-000001')
            ->assertJsonPath('data.name', 'Mechanical Keyboard')
            ->assertJsonPath('data.price', '450.50')
            ->assertJsonPath('data.currency', 'CAD')
            ->assertJsonMissingPath('data.metadata');

        $this->assertSame(1, Product::query()->count());
    }

    public function test_it_lists_paginated_products(): void
    {
        Product::factory()->count(16)->create();

        $response = $this->getJson('/api/products');

        $response
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
            ]);
    }

    public function test_it_shows_a_product(): void
    {
        $product = Product::factory()->create([
            'code' => 'PROD-000010',
            'name' => 'USB Hub',
        ]);

        $this->getJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.code', 'PROD-000010')
            ->assertJsonMissingPath('data.metadata');
    }

    public function test_it_updates_a_product(): void
    {
        $product = Product::factory()->create($this->productPayload());

        $this->putJson('/api/products/'.$product->id, $this->productPayload([
            'name' => 'Updated Keyboard',
            'price' => 499.99,
        ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Keyboard')
            ->assertJsonPath('data.price', '499.99');
    }

    public function test_it_inactivates_a_product_instead_of_deleting_it(): void
    {
        $product = Product::factory()->create(['active' => true]);

        $this->deleteJson('/api/products/'.$product->id)
            ->assertOk()
            ->assertJsonPath('data.active', false);

        $this->assertModelExists($product);
        $this->assertFalse($product->refresh()->active);
    }

    public function test_it_rejects_a_duplicate_code(): void
    {
        Product::factory()->create(['code' => 'PROD-000001']);

        $this->postJson('/api/products', $this->productPayload([
            'code' => 'PROD-000001',
            'name' => 'Another Product',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_it_rejects_a_duplicate_code_on_update_for_another_product(): void
    {
        Product::factory()->create(['code' => 'PROD-000001']);
        $product = Product::factory()->create(['code' => 'PROD-000002']);

        $this->putJson('/api/products/'.$product->id, $this->productPayload([
            'code' => 'PROD-000001',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_it_allows_keeping_the_same_code_on_update(): void
    {
        $product = Product::factory()->create($this->productPayload());

        $this->putJson('/api/products/'.$product->id, $this->productPayload([
            'name' => 'Renamed Keyboard',
        ]))
            ->assertOk()
            ->assertJsonPath('data.code', 'PROD-000001')
            ->assertJsonPath('data.name', 'Renamed Keyboard');
    }

    public function test_it_rejects_a_negative_price(): void
    {
        $this->postJson('/api/products', $this->productPayload([
            'price' => -1,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['price']);
    }

    public function test_it_stores_price_with_two_decimal_places(): void
    {
        $this->postJson('/api/products', $this->productPayload([
            'price' => 19.9,
        ]))->assertCreated();

        $this->assertSame('19.90', Product::query()->first()?->price);
    }

    public function test_it_trims_code_and_name_and_uppercases_currency(): void
    {
        $this->postJson('/api/products', $this->productPayload([
            'code' => '  PROD-000001  ',
            'name' => '  Mechanical Keyboard  ',
            'currency' => 'cad',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.code', 'PROD-000001')
            ->assertJsonPath('data.name', 'Mechanical Keyboard')
            ->assertJsonPath('data.currency', 'CAD');
    }

    public function test_it_searches_by_partial_name(): void
    {
        Product::factory()->create(['name' => 'Mechanical Keyboard', 'code' => 'PROD-000001']);
        Product::factory()->create(['name' => 'Wireless Mouse', 'code' => 'PROD-000002']);

        $this->getJson('/api/products?search=keyboard')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mechanical Keyboard');
    }

    public function test_it_searches_by_partial_code(): void
    {
        Product::factory()->create(['name' => 'Mechanical Keyboard', 'code' => 'PROD-000001']);
        Product::factory()->create(['name' => 'Wireless Mouse', 'code' => 'PROD-000002']);

        $this->getJson('/api/products?search=PROD-000001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'PROD-000001');
    }

    public function test_it_filters_by_exact_code(): void
    {
        Product::factory()->create(['code' => 'PROD-000001']);
        Product::factory()->create(['code' => 'PROD-000011']);

        $this->getJson('/api/products?code=PROD-000001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'PROD-000001');
    }

    public function test_it_filters_by_active_status(): void
    {
        Product::factory()->create(['name' => 'Active Product', 'active' => true]);
        Product::factory()->inactive()->create(['name' => 'Inactive Product']);

        $this->getJson('/api/products?active=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active Product');

        $this->getJson('/api/products?active=false')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Inactive Product');
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_it_rejects_invalid_payloads(array $payload, array $errors): void
    {
        $this->postJson('/api/products', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function invalidPayloadProvider(): array
    {
        $valid = [
            'code' => 'PROD-000001',
            'name' => 'Mechanical Keyboard',
            'description' => 'Full-size keyboard',
            'unit' => 'unit',
            'price' => 450.50,
            'currency' => 'CAD',
            'active' => true,
        ];

        return [
            'missing name' => [array_diff_key($valid, ['name' => true]), ['name']],
            'missing code' => [array_diff_key($valid, ['code' => true]), ['code']],
            'currency too short' => [array_merge($valid, ['currency' => 'BR']), ['currency']],
            'currency with digits' => [array_merge($valid, ['currency' => 'BR1']), ['currency']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productPayload(array $overrides = []): array
    {
        return [
            'code' => 'PROD-000001',
            'name' => 'Mechanical Keyboard',
            'description' => 'Full-size keyboard',
            'unit' => 'unit',
            'price' => 450.50,
            'currency' => 'CAD',
            'active' => true,
            ...$overrides,
        ];
    }
}
