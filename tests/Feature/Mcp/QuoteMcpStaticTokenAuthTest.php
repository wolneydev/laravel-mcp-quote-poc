<?php

namespace Tests\Feature\Mcp;

use App\Logging\RedactSensitiveLogContext;
use App\Models\Account;
use App\Models\Customer;
use App\Models\McpClientToken;
use App\Models\Product;
use App\Models\Quote;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Concerns\AuthenticatesQuoteMcp;
use Tests\TestCase;

class QuoteMcpStaticTokenAuthTest extends TestCase
{
    use AuthenticatesQuoteMcp;
    use LazilyRefreshDatabase;

    public function test_a_missing_bearer_token_returns_unauthorized(): void
    {
        $this->seedQuoteMcpAuth();

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]))
            ->assertUnauthorized()
            ->assertDontSee($this->quoteMcpToken);
    }

    public function test_an_invalid_bearer_token_returns_unauthorized(): void
    {
        $this->seedQuoteMcpAuth();

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders('invalid-token'))
            ->assertUnauthorized()
            ->assertDontSee($this->quoteMcpToken)
            ->assertDontSee($this->quoteMcpTokenHash);
    }

    public function test_a_valid_bearer_token_authenticates_the_configured_seller(): void
    {
        $seller = $this->seedQuoteMcpAuth();

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders())
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'Quote Server');

        $this->assertAuthenticatedAs($seller);
        $this->assertSame($seller->id, request()->user()?->id);
    }

    public function test_a_missing_stored_client_token_fails_closed(): void
    {
        $this->seedQuoteMcpAuth();
        McpClientToken::query()->delete();

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders())
            ->assertUnauthorized();
    }

    public function test_a_missing_configured_seller_fails_closed(): void
    {
        $this->seedQuoteMcpAuth();
        config(['mcp.quotes.seller_account_code' => 'VEN-999999']);

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders())
            ->assertUnauthorized();
    }

    public function test_a_configured_customer_account_is_rejected(): void
    {
        $customer = Account::factory()->customer()->create(['code' => 'CLI-000001']);
        $this->seedQuoteMcpAuth($customer);

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders())
            ->assertUnauthorized();
    }

    public function test_an_inactive_seller_is_rejected(): void
    {
        $seller = Account::factory()->seller()->inactive()->create(['code' => 'VEN-000001']);
        $this->seedQuoteMcpAuth($seller);

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders())
            ->assertUnauthorized();
    }

    public function test_a_seller_with_a_non_ven_code_is_rejected(): void
    {
        $seller = Account::factory()->seller()->create(['code' => 'ABC-000001']);
        $this->seedQuoteMcpAuth($seller);

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders())
            ->assertUnauthorized();
    }

    public function test_a_valid_seller_can_call_search_products_over_http(): void
    {
        $this->seedQuoteMcpAuth();
        Product::factory()->create([
            'code' => 'PROD-000001',
            'name' => 'Premium Keyboard',
        ]);

        $response = $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'tools/call', [
            'name' => 'search_products',
            'arguments' => ['query' => 'Keyboard'],
        ]), $this->quoteMcpHeaders())
            ->assertOk();

        $this->assertStringContainsString('PROD-000001', (string) $response->getContent());
        $this->assertResponseHasNoTokenMaterial($response->getContent());
    }

    public function test_a_valid_seller_can_call_search_customers_over_http(): void
    {
        $this->seedQuoteMcpAuth();
        $profile = Customer::factory()->create(['code' => 'CUST-000001', 'name' => 'Acme Ltd']);
        Account::factory()->customer($profile)->create(['code' => 'CLI-000001']);

        $response = $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'tools/call', [
            'name' => 'search_customers',
            'arguments' => ['query' => 'Acme'],
        ]), $this->quoteMcpHeaders())
            ->assertOk();

        $this->assertStringContainsString('CUST-000001', (string) $response->getContent());
        $this->assertResponseHasNoTokenMaterial($response->getContent());
    }

    public function test_a_valid_seller_can_call_generate_quote_report_over_http(): void
    {
        $this->seedQuoteMcpAuth();
        $profile = Customer::factory()->create(['code' => 'CUST-000001']);
        Account::factory()->customer($profile)->create(['code' => 'CLI-000001']);
        Product::factory()->create(['code' => 'PROD-000001', 'price' => '10.00', 'currency' => 'CAD']);

        $response = $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'tools/call', [
            'name' => 'generate_quote_report',
            'arguments' => [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                ],
            ],
        ]), $this->quoteMcpHeaders())
            ->assertOk();

        $this->assertSame(1, Quote::query()->count());
        $this->assertStringContainsString('QUO-', (string) $response->getContent());
        $this->assertResponseHasNoTokenMaterial($response->getContent());
    }

    public function test_an_invalid_token_cannot_create_a_quote(): void
    {
        $this->seedQuoteMcpAuth();
        $profile = Customer::factory()->create(['code' => 'CUST-000001']);
        Account::factory()->customer($profile)->create();
        Product::factory()->create(['code' => 'PROD-000001']);

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'tools/call', [
            'name' => 'generate_quote_report',
            'arguments' => [
                'customer' => 'CUST-000001',
                'items' => [
                    ['product' => 'PROD-000001', 'quantity' => 1],
                ],
            ],
        ]), $this->quoteMcpHeaders('wrong-token'))
            ->assertUnauthorized();

        $this->assertSame(0, Quote::query()->count());
    }

    public function test_https_is_required_outside_local_development(): void
    {
        $this->seedQuoteMcpAuth();
        $this->app['env'] = 'production';

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders())
            ->assertForbidden();

        $this->postJson('https://localhost/mcp/quotes', $this->quoteMcpPayload(2, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders())
            ->assertOk();
    }

    public function test_authorization_material_is_redacted_from_log_records(): void
    {
        $this->seedQuoteMcpAuth();
        $hash = $this->quoteMcpTokenHash;

        $processor = new RedactSensitiveLogContext;
        $record = $processor->process(new LogRecord(
            datetime: new DateTimeImmutable,
            channel: 'testing',
            level: Level::Info,
            message: 'Authorization: Bearer '.$this->quoteMcpToken,
            context: [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->quoteMcpToken,
                ],
                'token_hash' => $hash,
            ],
        ));

        $this->assertStringNotContainsString($this->quoteMcpToken, $record->message);
        $this->assertSame('[redacted]', $record->context['headers']['Authorization']);
        $this->assertSame('[redacted]', $record->context['token_hash']);
    }

    public function test_authentication_secrets_do_not_appear_in_logs_during_http_auth(): void
    {
        $this->seedQuoteMcpAuth();
        $logged = [];

        Log::listen(function (MessageLogged $event) use (&$logged): void {
            $logged[] = json_encode([$event->message, $event->context], JSON_THROW_ON_ERROR);
        });

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders())
            ->assertOk();

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(2, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders('invalid-token'))
            ->assertUnauthorized();

        $combined = implode("\n", $logged);

        $this->assertStringNotContainsString($this->quoteMcpToken, $combined);
        $this->assertStringNotContainsString($this->quoteMcpTokenHash, $combined);
    }

    private function assertResponseHasNoTokenMaterial(?string $content): void
    {
        $body = (string) $content;

        $this->assertStringNotContainsString($this->quoteMcpToken, $body);
        $this->assertStringNotContainsString($this->quoteMcpTokenHash, $body);
        $this->assertStringNotContainsString('Authorization', $body);
    }
}
