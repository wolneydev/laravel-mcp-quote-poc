<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Lookups\ProductLookup;
use App\Mcp\Prompts\GenerateQuoteReportPrompt;
use App\Mcp\Servers\QuoteServer;
use App\Mcp\Tools\GenerateQuoteReportTool;
use App\Mcp\Tools\GetQuoteReportTool;
use App\Mcp\Tools\SearchCustomersTool;
use App\Mcp\Tools\SearchProductsTool;
use App\Models\Account;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class QuoteMcpPromptTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_prompt_is_registered_as_generate_quote_report(): void
    {
        $seller = Account::factory()->seller()->create();

        QuoteServer::actingAs($seller)
            ->prompt(GenerateQuoteReportPrompt::class)
            ->assertOk()
            ->assertName('generate-quote-report')
            ->assertSee('/generate-quote-report')
            ->assertSee('search_products')
            ->assertSee('search_customers')
            ->assertSee('generate_quote_report')
            ->assertSee('get_quote_report')
            ->assertSee('not approved')
            ->assertSee('Never send `unit_price`')
            ->assertSee('Never request, mention, or send bearer tokens')
            ->assertDontSee('CreateQuoteAction')
            ->assertDontSee('Bearer')
            ->assertDontSee('MCP_QUOTE_TOKEN');
    }

    public function test_the_prompt_accepts_optional_arguments_and_allows_missing_values(): void
    {
        $seller = Account::factory()->seller()->create();

        QuoteServer::actingAs($seller)
            ->prompt(GenerateQuoteReportPrompt::class)
            ->assertOk()
            ->assertSee('customer: (not provided)')
            ->assertSee('product: (not provided)')
            ->assertSee('quantity: (not provided)');

        QuoteServer::actingAs($seller)
            ->prompt(GenerateQuoteReportPrompt::class, [
                'customer' => 'Acme Ltd',
                'product' => 'Premium Keyboard',
                'quantity' => '3',
                'seller_account_code' => 'VEN-000001',
                'valid_until' => '2026-09-30',
                'notes' => 'Optional',
            ])
            ->assertOk()
            ->assertSee('customer: Acme Ltd')
            ->assertSee('product: Premium Keyboard')
            ->assertSee('quantity: 3')
            ->assertSee('seller_account_code: VEN-000001')
            ->assertSee('valid_until: 2026-09-30')
            ->assertSee('notes: Optional');
    }

    public function test_quote_tool_schemas_document_required_fields_and_limits(): void
    {
        $searchProducts = (new SearchProductsTool(app(ProductLookup::class)))->toArray();
        $generate = app(GenerateQuoteReportTool::class)->toArray();
        $get = app(GetQuoteReportTool::class)->toArray();
        $searchCustomers = app(SearchCustomersTool::class)->toArray();
        $prompt = (new GenerateQuoteReportPrompt)->toArray();

        $this->assertSame('search_products', $searchProducts['name']);
        $this->assertSame('search_customers', $searchCustomers['name']);
        $this->assertSame('generate_quote_report', $generate['name']);
        $this->assertSame('get_quote_report', $get['name']);
        $this->assertSame('generate-quote-report', $prompt['name']);
        $this->assertFalse($prompt['arguments'][0]['required']);

        $this->assertArrayHasKey('query', $searchProducts['inputSchema']['properties']);
        $this->assertContains('query', $searchProducts['inputSchema']['required']);
        $this->assertSame(10, $searchProducts['inputSchema']['properties']['limit']['maximum']);
        $this->assertContains('customer', $generate['inputSchema']['required']);
        $this->assertContains('items', $generate['inputSchema']['required']);
        $this->assertSame(0.01, $generate['inputSchema']['properties']['items']['items']['properties']['quantity']['minimum']);
    }
}
