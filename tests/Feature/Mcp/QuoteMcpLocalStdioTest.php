<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Prompts\GenerateQuoteReportPrompt;
use App\Mcp\Servers\ApplicationServer;
use App\Mcp\Servers\QuoteServer;
use App\Mcp\Support\BindLocalQuoteMcpSeller;
use App\Mcp\Tools\SearchProductsTool;
use App\Models\Account;
use App\Models\Product;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server\Transport\HttpTransport;
use Laravel\Mcp\Server\Transport\StdioTransport;
use Tests\Concerns\AuthenticatesQuoteMcp;
use Tests\TestCase;

class QuoteMcpLocalStdioTest extends TestCase
{
    use AuthenticatesQuoteMcp;
    use LazilyRefreshDatabase;

    public function test_the_local_quotes_server_exposes_quote_tools_and_the_prompt(): void
    {
        $this->seedLocalQuoteSeller();

        $context = $this->startLocalQuoteServer()->createContext();

        $this->assertSame(
            ['search_products', 'search_customers', 'generate_quote_report', 'get_quote_report'],
            $context->tools()->map(fn ($tool): string => $tool->name())->all(),
        );
        $this->assertSame(
            ['generate-quote-report'],
            $context->prompts()->map(fn ($prompt): string => $prompt->name())->all(),
        );
    }

    public function test_the_application_local_server_exposes_only_health_check(): void
    {
        $server = $this->app->make(ApplicationServer::class, [
            'transport' => new StdioTransport('application-session'),
        ]);
        $server->start();

        $this->assertSame(
            ['health_check'],
            $server->createContext()->tools()->map(fn ($tool): string => $tool->name())->all(),
        );
        $this->assertSame([], $server->createContext()->prompts()->all());
    }

    public function test_local_stdio_binds_the_configured_seller_and_can_search_products(): void
    {
        $seller = $this->seedLocalQuoteSeller();
        Product::factory()->create([
            'code' => 'PROD-000001',
            'name' => 'Premium Keyboard',
        ]);

        $this->startLocalQuoteServer();

        $this->assertAuthenticatedAs($seller);

        QuoteServer::tool(SearchProductsTool::class, ['query' => 'Keyboard'])
            ->assertOk()
            ->assertSee('PROD-000001');

        QuoteServer::prompt(GenerateQuoteReportPrompt::class)
            ->assertOk()
            ->assertName('generate-quote-report');
    }

    public function test_missing_seller_config_fails_closed_on_local_stdio(): void
    {
        config(['mcp.quotes.seller_account_code' => '']);

        $this->startLocalQuoteServer();

        $this->assertGuest();

        QuoteServer::tool(SearchProductsTool::class, ['query' => 'Keyboard'])
            ->assertHasErrors(['Authentication is required.']);
    }

    public function test_an_inactive_seller_fails_closed_on_local_stdio(): void
    {
        $seller = Account::factory()->seller()->inactive()->create(['code' => 'VEN-000001']);
        config(['mcp.quotes.seller_account_code' => $seller->code]);

        $this->startLocalQuoteServer();

        $this->assertGuest();

        QuoteServer::tool(SearchProductsTool::class, ['query' => 'Keyboard'])
            ->assertHasErrors(['Authentication is required.']);
    }

    public function test_a_customer_account_fails_closed_on_local_stdio(): void
    {
        $customer = Account::factory()->customer()->create(['code' => 'CLI-000001']);
        config(['mcp.quotes.seller_account_code' => $customer->code]);

        $this->startLocalQuoteServer();

        $this->assertGuest();

        QuoteServer::tool(SearchProductsTool::class, ['query' => 'Keyboard'])
            ->assertHasErrors(['Authentication is required.']);
    }

    public function test_local_stdio_still_starts_when_the_seller_table_is_missing(): void
    {
        config(['mcp.quotes.seller_account_code' => 'VEN-000001']);
        Schema::drop('accounts');

        $this->startLocalQuoteServer();

        $this->assertGuest();
    }

    public function test_a_seller_with_a_non_ven_code_fails_closed_on_local_stdio(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'ABC-000001']);
        config(['mcp.quotes.seller_account_code' => $seller->code]);

        $this->startLocalQuoteServer();

        $this->assertGuest();

        QuoteServer::tool(SearchProductsTool::class, ['query' => 'Keyboard'])
            ->assertHasErrors(['Authentication is required.']);
    }

    public function test_production_stdio_does_not_auto_bind_a_seller(): void
    {
        $seller = $this->seedLocalQuoteSeller();
        $this->app['env'] = 'production';

        $this->startLocalQuoteServer();

        $this->assertGuest();
        $this->assertNotSame($seller->id, auth()->id());

        QuoteServer::tool(SearchProductsTool::class, ['query' => 'Keyboard'])
            ->assertHasErrors(['Authentication is required.']);
    }

    public function test_http_quote_mcp_still_requires_a_usable_client_token(): void
    {
        $this->seedLocalQuoteSeller();

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]))->assertUnauthorized();
    }

    public function test_http_transport_does_not_use_the_local_stdio_binder(): void
    {
        $this->seedLocalQuoteSeller();

        app(BindLocalQuoteMcpSeller::class)->bindIfLocalStdio(
            new HttpTransport(request(), 'http-session'),
        );

        $this->assertGuest();
    }

    private function seedLocalQuoteSeller(): Account
    {
        $seller = Account::factory()->seller()->create(['code' => 'VEN-000001']);

        config(['mcp.quotes.seller_account_code' => $seller->code]);

        return $seller;
    }

    private function startLocalQuoteServer(): QuoteServer
    {
        $server = $this->app->make(QuoteServer::class, [
            'transport' => new StdioTransport('quotes-session'),
        ]);
        $server->start();

        return $server;
    }
}
