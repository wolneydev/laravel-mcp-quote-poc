<?php

namespace Tests\Feature;

use App\Models\McpClientToken;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AuthenticatesMcpTokenAdministration;
use Tests\Concerns\AuthenticatesQuoteMcp;
use Tests\TestCase;

class McpClientTokenApiTest extends TestCase
{
    use AuthenticatesMcpTokenAdministration;
    use AuthenticatesQuoteMcp;
    use LazilyRefreshDatabase;

    public function test_a_missing_administration_bearer_token_returns_unauthorized(): void
    {
        $this->seedMcpTokenAdministrationAuth();

        $this->getJson('/api/mcp-client-tokens')
            ->assertUnauthorized();
    }

    public function test_an_invalid_administration_bearer_token_returns_unauthorized(): void
    {
        $this->seedMcpTokenAdministrationAuth();

        $this->getJson('/api/mcp-client-tokens', $this->mcpAdministrationHeaders('invalid-token'))
            ->assertUnauthorized()
            ->assertDontSee($this->mcpAdministrationToken);
    }

    public function test_a_missing_administration_hash_fails_closed(): void
    {
        $this->seedMcpTokenAdministrationAuth();
        config(['mcp.administration.token_hash' => '']);

        $this->getJson('/api/mcp-client-tokens', $this->mcpAdministrationHeaders())
            ->assertUnauthorized();
    }

    public function test_an_mcp_client_token_cannot_access_administration_routes(): void
    {
        $this->seedMcpTokenAdministrationAuth();
        $this->seedQuoteMcpAuth();

        $this->getJson('/api/mcp-client-tokens', $this->quoteMcpHeaders())
            ->assertUnauthorized();
    }

    public function test_it_creates_a_token_persisting_only_the_hash_and_returning_the_raw_token_once(): void
    {
        $this->seedMcpTokenAdministrationAuth();

        $response = $this->postJson('/api/mcp-client-tokens', [
            'expires_in_days' => 90,
        ], $this->mcpAdministrationHeaders())
            ->assertCreated()
            ->assertJsonPath('data.revoked', false)
            ->assertJsonPath('data.expires_in_days', 90)
            ->assertJsonMissingPath('data.token_hash');

        $rawToken = $response->json('data.token');

        $this->assertIsString($rawToken);
        $this->assertSame(64, strlen($rawToken));
        $this->assertSame(1, McpClientToken::query()->count());

        $stored = McpClientToken::query()->first();

        $this->assertNotNull($stored);
        $this->assertSame(hash('sha256', $rawToken), $stored->token_hash);
        $this->assertFalse($stored->revoked);
        $this->assertSame(90, $stored->expires_in_days);
        $this->assertStringNotContainsString($rawToken, (string) $stored->getRawOriginal('token_hash'));
    }

    public function test_index_and_show_omit_the_raw_token(): void
    {
        $this->seedMcpTokenAdministrationAuth();

        $create = $this->postJson('/api/mcp-client-tokens', [
            'expires_in_days' => 30,
        ], $this->mcpAdministrationHeaders())
            ->assertCreated();

        $rawToken = $create->json('data.token');
        $id = $create->json('data.id');

        $this->getJson('/api/mcp-client-tokens', $this->mcpAdministrationHeaders())
            ->assertOk()
            ->assertJsonMissingPath('data.0.token')
            ->assertJsonMissingPath('data.0.token_hash')
            ->assertDontSee($rawToken);

        $this->getJson('/api/mcp-client-tokens/'.$id, $this->mcpAdministrationHeaders())
            ->assertOk()
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_hash')
            ->assertJsonPath('data.id', $id)
            ->assertDontSee($rawToken);
    }

    public function test_it_lists_paginated_tokens_and_filters_by_revoked(): void
    {
        $this->seedMcpTokenAdministrationAuth();
        McpClientToken::factory()->count(2)->create();
        McpClientToken::factory()->revoked()->create();

        $this->getJson('/api/mcp-client-tokens', $this->mcpAdministrationHeaders())
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data',
                'links',
                'meta',
            ]);

        $this->getJson('/api/mcp-client-tokens?revoked=true', $this->mcpAdministrationHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.revoked', true);

        $this->getJson('/api/mcp-client-tokens?revoked=false', $this->mcpAdministrationHeaders())
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_update_can_set_revoked_true(): void
    {
        $this->seedMcpTokenAdministrationAuth();
        $token = McpClientToken::factory()->create(['expires_in_days' => 90]);

        $this->patchJson('/api/mcp-client-tokens/'.$token->id, [
            'revoked' => true,
        ], $this->mcpAdministrationHeaders())
            ->assertOk()
            ->assertJsonPath('data.revoked', true)
            ->assertJsonMissingPath('data.token');

        $this->assertTrue($token->refresh()->revoked);
    }

    public function test_update_can_change_expires_in_days(): void
    {
        $this->seedMcpTokenAdministrationAuth();
        $token = McpClientToken::factory()->create(['expires_in_days' => 90]);

        $this->putJson('/api/mcp-client-tokens/'.$token->id, [
            'expires_in_days' => 14,
        ], $this->mcpAdministrationHeaders())
            ->assertOk()
            ->assertJsonPath('data.expires_in_days', 14);

        $this->assertSame(14, $token->refresh()->expires_in_days);
    }

    public function test_delete_revokes_the_token_without_deleting_the_row(): void
    {
        $this->seedMcpTokenAdministrationAuth();
        $token = McpClientToken::factory()->create();

        $this->deleteJson('/api/mcp-client-tokens/'.$token->id, [], $this->mcpAdministrationHeaders())
            ->assertOk()
            ->assertJsonPath('data.revoked', true)
            ->assertJsonMissingPath('data.token');

        $this->assertModelExists($token);
        $this->assertTrue($token->refresh()->revoked);
    }

    #[DataProvider('invalidPayloadProvider')]
    public function test_it_rejects_invalid_expires_in_days(array $payload, array $errors): void
    {
        $this->seedMcpTokenAdministrationAuth();

        $this->postJson('/api/mcp-client-tokens', $payload, $this->mcpAdministrationHeaders())
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errors);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function invalidPayloadProvider(): array
    {
        return [
            'missing expires_in_days' => [[], ['expires_in_days']],
            'zero expires_in_days' => [['expires_in_days' => 0], ['expires_in_days']],
            'negative expires_in_days' => [['expires_in_days' => -1], ['expires_in_days']],
        ];
    }
}
