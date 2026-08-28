<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApplicationMcpHttpTest extends TestCase
{
    public function test_mcp_http_endpoint_initializes(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-11-25',
                'capabilities' => new \stdClass,
                'clientInfo' => [
                    'name' => 'phpunit',
                    'version' => '1.0.0',
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'Application Server')
            ->assertJsonPath('result.protocolVersion', '2025-11-25');
    }

    public function test_mcp_http_endpoint_lists_the_health_check_tool(): void
    {
        $tools = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => new \stdClass,
        ])
            ->assertOk()
            ->json('result.tools');

        $this->assertSame(['health_check'], array_column($tools, 'name'));
    }
}
