<?php

namespace Tests\Feature\Mcp;

use App\Models\McpClientToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Concerns\AuthenticatesMcpTokenAdministration;
use Tests\Concerns\AuthenticatesQuoteMcp;
use Tests\TestCase;

class QuoteMcpClientTokenAuthTest extends TestCase
{
    use AuthenticatesMcpTokenAdministration;
    use AuthenticatesQuoteMcp;
    use LazilyRefreshDatabase;

    public function test_a_usable_token_authenticates_the_configured_seller(): void
    {
        $seller = $this->seedQuoteMcpAuth();

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders())
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'Quote Server')
            ->assertDontSee($this->quoteMcpToken)
            ->assertDontSee($this->quoteMcpTokenHash);

        $this->assertAuthenticatedAs($seller);
        $this->assertSame($seller->id, request()->user()?->id);
    }

    public function test_an_invalid_token_returns_the_same_unauthorized_payload_as_revoked_and_expired_tokens(): void
    {
        $this->seedQuoteMcpAuth();

        $invalid = $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders('invalid-token'))
            ->assertUnauthorized();

        $revokedRaw = bin2hex(random_bytes(32));
        McpClientToken::factory()->revoked()->create([
            'token_hash' => hash('sha256', $revokedRaw),
        ]);

        $revoked = $this->postJson('/mcp/quotes', $this->quoteMcpPayload(2, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders($revokedRaw))
            ->assertUnauthorized();

        $expiredRaw = bin2hex(random_bytes(32));
        McpClientToken::factory()->expired()->create([
            'token_hash' => hash('sha256', $expiredRaw),
        ]);

        $expired = $this->postJson('/mcp/quotes', $this->quoteMcpPayload(3, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders($expiredRaw))
            ->assertUnauthorized();

        $this->assertSame($invalid->status(), $revoked->status());
        $this->assertSame($invalid->json('message'), $revoked->json('message'));
        $this->assertSame($invalid->status(), $expired->status());
        $this->assertSame($invalid->json('message'), $expired->json('message'));
        $this->assertStringNotContainsString($this->quoteMcpToken, (string) $invalid->getContent());
        $this->assertSame('Unauthorized', $invalid->json('message'));
    }

    public function test_an_administration_token_cannot_call_quote_mcp(): void
    {
        $this->seedQuoteMcpAuth();
        $this->seedMcpTokenAdministrationAuth();

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->mcpAdministrationHeaders())
            ->assertUnauthorized();
    }

    public function test_an_env_quote_token_hash_alone_does_not_authenticate(): void
    {
        $seller = $this->seedQuoteMcpAuth();
        McpClientToken::query()->delete();

        config([
            'mcp.quotes.token_hash' => $this->quoteMcpTokenHash,
            'mcp.quotes.seller_account_code' => $seller->code,
        ]);

        $this->postJson('/mcp/quotes', $this->quoteMcpPayload(1, 'initialize', [
            'protocolVersion' => '2025-11-25',
            'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'phpunit', 'version' => '1.0.0'],
        ]), $this->quoteMcpHeaders())
            ->assertUnauthorized();
    }
}
