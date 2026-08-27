<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\McpClientToken;

trait AuthenticatesQuoteMcp
{
    protected string $quoteMcpToken = '';

    protected string $quoteMcpTokenHash = '';

    protected function seedQuoteMcpAuth(?Account $seller = null): Account
    {
        $seller ??= Account::factory()->seller()->create(['code' => 'VEN-000001']);
        $this->quoteMcpToken = bin2hex(random_bytes(32));
        $this->quoteMcpTokenHash = hash('sha256', $this->quoteMcpToken);

        McpClientToken::query()->create([
            'token_hash' => $this->quoteMcpTokenHash,
            'revoked' => false,
            'expires_in_days' => 90,
        ]);

        config([
            'mcp.quotes.seller_account_code' => $seller->code,
        ]);

        return $seller;
    }

    /**
     * @return array<string, string>
     */
    protected function quoteMcpHeaders(?string $token = null): array
    {
        return [
            'Authorization' => 'Bearer '.($token ?? $this->quoteMcpToken),
        ];
    }

    /**
     * @param  array<string, mixed>|object  $params
     * @return array<string, mixed>
     */
    protected function quoteMcpPayload(int $id, string $method, array|object $params = new \stdClass): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ];
    }
}
