# MCP Health Check

## 1. Overview and Goal

This specification defines the first application-specific Laravel MCP tool: a simple health check that lets an LLM verify that the application MCP server is operational and receive a greeting.

It also records the transport and client configuration required for that tool to appear in MCP clients (Cursor, Claude Code, Claude Desktop) when the project runs in Docker Compose.

This specification builds on `.specs/AI-TOOLING.md`. Laravel Boost MCP and the application MCP server must remain separate.

---

## 2. Tool Requirements

Create an MCP tool named:

```text
health_check
```

The tool must:

* Be registered on `App\Mcp\Servers\ApplicationServer`.
* Be implemented as `App\Mcp\Tools\HealthCheckTool`.
* Use the Laravel MCP `#[Name('health_check')]` attribute so the public tool name is `health_check`, not the default kebab-case class name.
* Be marked read-only with `#[IsReadOnly]`.
* Describe itself for LLMs as a way to verify that the Laravel MCP server is operational and receive a greeting.
* Accept no input parameters.
* Return a text greeting:

```text
Hello! The Laravel MCP server is operational.
```

Generate the class with Laravel's official Artisan command inside the application container:

```bash
docker compose exec app php artisan make:mcp-tool HealthCheckTool --no-interaction
```

Do not add extra domain tools in this specification.

---

## 3. Application Server Registration

Register `HealthCheckTool` on `ApplicationServer`:

```php
protected array $tools = [
    HealthCheckTool::class,
];
```

Server instructions must tell clients to use `health_check` to verify that the server is operational.

---

## 4. Transports

`health_check` must be reachable through both Laravel MCP transports.

### 4.1 Local (stdio)

Keep the local handle for Claude Desktop, Claude Code, Cursor, and `mcp:start`:

```php
Mcp::local('application', ApplicationServer::class);
```

Local clients must start the server with:

```bash
docker compose exec -T app php artisan mcp:start application
```

The `-T` flag is required. Do not run `mcp:start` as a long-lived Docker Compose service. It is a stdio process that the MCP client must spawn.

### 4.2 Web (HTTP)

Also register an HTTP server so the tool is available whenever Docker Compose is running:

```php
Mcp::web('/mcp', ApplicationServer::class);
```

The public HTTP endpoint must be:

```text
http://localhost:8890/mcp
```

This URL is served by the existing Nginx container on host port `8890`. No extra Compose service is required.

---

## 5. HTTP and Docker Configuration

CSRF verification must exclude the MCP HTTP routes in `bootstrap/app.php`:

```php
$middleware->validateCsrfTokens(except: [
    'mcp',
    'mcp/*',
]);
```

Nginx FastCGI buffering must be disabled for PHP so streamed MCP responses are not held back:

```nginx
location ~ \.php$ {
    fastcgi_pass app:9000;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    include fastcgi_params;
    fastcgi_hide_header X-Powered-By;
    fastcgi_buffering off;
    gzip off;
}
```

Do not change the existing host ports (`8890` for HTTP, `5439` for PostgreSQL).

---

## 6. MCP Client Configuration

### 6.1 Cursor

Project file:

```text
.cursor/mcp.json
```

Must include both Laravel Boost and the application server:

```json
{
    "mcpServers": {
        "laravel-boost": {
            "command": "docker",
            "args": [
                "compose",
                "exec",
                "-T",
                "app",
                "php",
                "artisan",
                "boost:mcp"
            ]
        },
        "laravel-application": {
            "command": "docker",
            "args": [
                "compose",
                "exec",
                "-T",
                "app",
                "php",
                "artisan",
                "mcp:start",
                "application"
            ]
        }
    }
}
```

Docker Compose must be running before Cursor starts `laravel-application`. After changing this file, reload MCP servers in Cursor.

### 6.2 Claude Code

Project file:

```text
.mcp.json
```

Must expose the application server the same way:

```json
{
    "mcpServers": {
        "laravel-application": {
            "command": "docker",
            "args": [
                "compose",
                "exec",
                "-T",
                "app",
                "php",
                "artisan",
                "mcp:start",
                "application"
            ]
        }
    }
}
```

### 6.3 Claude Desktop (Windows + WSL)

If Claude Desktop runs on Windows and Docker runs inside WSL, `claude_desktop_config.json` must invoke WSL:

```json
{
  "mcpServers": {
    "laravel-application": {
      "command": "wsl",
      "args": [
        "-e",
        "bash",
        "-lc",
        "cd /home/wolneyc/projetos/Laravel/markdown-processing-mcp && docker compose exec -T app php artisan mcp:start application"
      ]
    }
  }
}
```

Adjust the project path if the repository lives elsewhere. Restart Claude Desktop after saving the file. The `health_check` tool appears under the `laravel-application` server.

### 6.4 Claude.ai (browser)

Custom connectors on claude.ai are reached from Anthropic's servers. They cannot use `http://localhost:8890/mcp`.

For the browser product, expose `/mcp` on a public HTTPS URL (for example with a tunnel) and register that URL as a custom connector.

---

## 7. Tests

Cover both the tool primitive and the HTTP transport.

### 7.1 Tool primitive

File:

```text
tests/Feature/HealthCheckToolTest.php
```

Invoke the tool through `ApplicationServer`:

```php
$response = ApplicationServer::tool(HealthCheckTool::class);

$response
    ->assertOk()
    ->assertName('health_check')
    ->assertSee('Hello! The Laravel MCP server is operational.');
```

### 7.2 HTTP endpoint

File:

```text
tests/Feature/ApplicationMcpHttpTest.php
```

Must prove:

* `POST /mcp` with JSON-RPC `initialize` returns server info `Application Server`.
* `POST /mcp` with JSON-RPC `tools/list` includes a tool named `health_check`.

Run the related tests inside the application container:

```bash
docker compose exec app php artisan test --compact --filter=HealthCheckToolTest
docker compose exec app php artisan test --compact --filter=ApplicationMcpHttpTest
```

---

## 8. Validation Commands

Confirm the HTTP route exists:

```bash
docker compose exec app php artisan route:list --path=mcp
```

Confirm the live endpoint while Compose is up:

```bash
curl -sS -X POST http://localhost:8890/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-11-25","capabilities":{},"clientInfo":{"name":"curl","version":"1.0.0"}}}'
```

The response must include `serverInfo.name` equal to `Application Server`.

List tools:

```bash
curl -sS -X POST http://localhost:8890/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

The listed tools must include `health_check`.

---

## 9. Definition of Done

* [ ] `health_check` exists as `App\Mcp\Tools\HealthCheckTool` with `#[Name('health_check')]`.
* [ ] The tool returns the greeting `Hello! The Laravel MCP server is operational.`
* [ ] `ApplicationServer` registers `HealthCheckTool`.
* [ ] `routes/ai.php` registers both `Mcp::local('application', ...)` and `Mcp::web('/mcp', ...)`.
* [ ] CSRF exceptions include `mcp` and `mcp/*`.
* [ ] Nginx PHP FastCGI buffering is disabled.
* [ ] `.cursor/mcp.json` starts `laravel-application` via `docker compose exec -T app php artisan mcp:start application`.
* [ ] `.mcp.json` starts the same local server for Claude Code.
* [ ] `http://localhost:8890/mcp` initializes while Docker Compose is running.
* [ ] Feature tests for the tool and the HTTP endpoint pass.

---

## 10. Expected Result

After this specification, an LLM connected to the application MCP server can call `health_check` and receive a greeting that confirms the server is operational.

Clients may reach that tool in two ways:

```text
Cursor / Claude Code / Claude Desktop
        |
        | stdio
        v
docker compose exec -T app php artisan mcp:start application
        |
        v
health_check
```

```text
HTTP MCP client
        |
        v
http://localhost:8890/mcp
        |
        v
Nginx :8890  ->  PHP-FPM (app)
        |
        v
health_check
```
