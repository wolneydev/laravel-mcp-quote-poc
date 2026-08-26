<?php

namespace Tests\Feature;

use App\Mcp\Servers\ApplicationServer;
use App\Mcp\Tools\HealthCheckTool;
use Tests\TestCase;

class HealthCheckToolTest extends TestCase
{
    public function test_health_check_tool_returns_a_greeting(): void
    {
        $response = ApplicationServer::tool(HealthCheckTool::class);

        $response
            ->assertOk()
            ->assertName('health_check')
            ->assertSee('Hello! The Laravel MCP server is operational.');
    }
}
