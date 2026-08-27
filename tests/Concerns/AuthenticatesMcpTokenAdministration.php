<?php

namespace Tests\Concerns;

trait AuthenticatesMcpTokenAdministration
{
    protected string $mcpAdministrationToken = '';

    protected function seedMcpTokenAdministrationAuth(): void
    {
        $this->mcpAdministrationToken = bin2hex(random_bytes(32));

        config([
            'mcp.administration.token_hash' => hash('sha256', $this->mcpAdministrationToken),
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function mcpAdministrationHeaders(?string $token = null): array
    {
        return [
            'Authorization' => 'Bearer '.($token ?? $this->mcpAdministrationToken),
        ];
    }
}
