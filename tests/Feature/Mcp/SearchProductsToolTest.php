<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\QuoteServer;
use App\Mcp\Tools\SearchProductsTool;
use App\Models\Account;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SearchProductsToolTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_finds_a_product_by_exact_code_and_partial_name(): void
    {
        $seller = Account::factory()->seller()->create();
        $keyboard = Product::factory()->create([
            'code' => 'PROD-000001',
            'name' => 'Premium Keyboard',
            'price' => '10.00',
            'currency' => 'CAD',
        ]);
        Product::factory()->create([
            'code' => 'PROD-000002',
            'name' => 'Office Chair',
        ]);

        QuoteServer::actingAs($seller)
            ->tool(SearchProductsTool::class, ['query' => 'PROD-000001'])
            ->assertOk()
            ->assertName('search_products')
            ->assertStructuredContent(function ($json) use ($keyboard): void {
                $json->has('products', 1)
                    ->where('products.0.id', $keyboard->id)
                    ->where('products.0.code', 'PROD-000001')
                    ->where('products.0.name', 'Premium Keyboard')
                    ->where('products.0.price', '10.00')
                    ->where('products.0.currency', 'CAD')
                    ->etc();
            });

        QuoteServer::actingAs($seller)
            ->tool(SearchProductsTool::class, ['query' => 'Keyboard'])
            ->assertOk()
            ->assertSee('PROD-000001')
            ->assertDontSee('PROD-000002');
    }

    public function test_inactive_products_are_excluded_by_default_and_the_limit_is_enforced(): void
    {
        $seller = Account::factory()->seller()->create();
        Product::factory()->inactive()->create([
            'code' => 'PROD-000099',
            'name' => 'Retired Keyboard',
        ]);

        foreach (range(1, 12) as $index) {
            Product::factory()->create([
                'name' => 'Bulk Widget '.$index,
            ]);
        }

        QuoteServer::actingAs($seller)
            ->tool(SearchProductsTool::class, ['query' => 'PROD-000099'])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->has('products', 0)->etc());

        QuoteServer::actingAs($seller)
            ->tool(SearchProductsTool::class, ['query' => 'PROD-000099', 'active_only' => false])
            ->assertOk()
            ->assertSee('PROD-000099');

        QuoteServer::actingAs($seller)
            ->tool(SearchProductsTool::class, ['query' => 'Bulk Widget', 'limit' => 10])
            ->assertOk()
            ->assertStructuredContent(fn ($json) => $json->has('products', 10)->etc());
    }

    public function test_unauthenticated_and_non_seller_accounts_cannot_search_products(): void
    {
        Product::factory()->create(['name' => 'Premium Keyboard']);

        QuoteServer::tool(SearchProductsTool::class, ['query' => 'Keyboard'])
            ->assertHasErrors(['Authentication is required.']);

        $customer = Account::factory()->customer()->create();

        QuoteServer::actingAs($customer)
            ->tool(SearchProductsTool::class, ['query' => 'Keyboard'])
            ->assertHasErrors();
    }
}
