<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\HealthCheckTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Application Server')]
#[Version('0.0.1')]
#[Instructions('Application MCP server. Use the health_check tool to verify the server is operational.')]
class ApplicationServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        HealthCheckTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
