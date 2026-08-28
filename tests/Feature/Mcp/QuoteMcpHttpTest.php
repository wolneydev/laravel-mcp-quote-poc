<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\QuoteServer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\AuthenticatesQuoteMcp;
use Tests\TestCase;

class QuoteMcpHttpTest extends TestCase
{
    use AuthenticatesQuoteMcp;
    use LazilyRefreshDatabase;

    public function test_the_quote_mcp_http_endpoint_requires_authentication(): void
    {
        $this->seedQuoteMcpAuth();

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => [
                'name' => 'phpunit',
                'version' => '1.0.0',
            ],
        ]))->assertUnauthorized();
    }

    public function test_an_authenticated_seller_can_list_quote_tools_and_the_prompt(): void
    {
        $this->seedQuoteMcpAuth();

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => [
                'name' => 'phpunit',
                'version' => '1.0.0',
            ],
        ]), $this->quoteMcpHeaders())
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'Quote Server');

        $tools = $this->postJson('/mcp/quotes', $this->quoteMcpPayload(2, 'tools/list'), $this->quoteMcpHeaders())
            ->assertOk()
            ->json('result.tools');

        $this->assertSame(
            ['search_products', 'search_customers', 'generate_quote_report', 'get_quote_report', 'generate_quote_draft_pdf'],
            array_column($tools, 'name'),
        );

        $prompts = $this->postJson('/mcp/quotes', $this->quoteMcpPayload(3, 'prompts/list'), $this->quoteMcpHeaders())
            ->assertOk()
            ->json('result.prompts');

        $this->assertSame(
            ['generate-quote-report', 'generate-quote-draft-pdf'],
            array_column($prompts, 'name'),
        );
    }

    public function test_api_routes_do_not_expose_a_duplicate_mcp_endpoint(): void
    {
        $this->postJson('/api/mcp/quotes')->assertNotFound();
        $this->getJson('/api/mcp')->assertNotFound();

        $this->assertTrue(class_exists(QuoteServer::class));
    }
}
