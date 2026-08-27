# Restore Local Claude Code Quote MCP Tools

## Why

TASK-008 moved Quote MCP web authentication from `MCP_QUOTE_TOKEN_HASH` to usable rows in `mcp_client_tokens`. Claude Code still connects through project `.mcp.json`.

That file exposes two servers:

- `laravel-application` — local `stdio` (`php artisan mcp:start application`). It only has `health_check`.
- `laravel-quotes` — HTTP `http://localhost:8890/mcp/quotes` with `Authorization: Bearer ${env:MCP_QUOTE_TOKEN}`.

After TASK-008, the HTTP quotes server rejects a bearer token that is not a stored, unrevoked, unexpired `mcp_client_tokens` hash. Local setup still documents putting a SHA-256 hash in `.env` as `MCP_QUOTE_TOKEN_HASH`, which the middleware no longer consults.

Claude Code then fails to connect `laravel-quotes`. The `/mcp` tool picker only shows **Tools for laravel-application** with **1 tool**: `health_check`. Quote tools (`search_products`, `search_customers`, `generate_quote_report`, `get_quote_report`) and the `generate-quote-report` prompt disappear.

Operators need those tools visible and callable again from the **Claude Code terminal** on a local Docker Compose machine, without weakening TASK-008 fail-closed authentication on the public HTTP endpoint.

## What

Restore local Claude Code visibility and access to the full Quote MCP surface.

Use the same local `stdio` pattern already proven for `health_check`:

```bash
docker compose exec -T app php artisan mcp:start quotes
```

Claude Code must list and call Quote MCP tools from that local process. Seller identity for that local process comes from the configured `MCP_QUOTE_SELLER_ACCOUNT_CODE`, not from a bearer token (stdio has no HTTP `Authorization` header).

Keep TASK-008 unchanged for `POST /mcp/quotes`:

- bearer token required
- SHA-256 lookup in `mcp_client_tokens`
- reject missing, unknown, revoked, and expired tokens with the same `401`
- resolve `$request->user()` from `MCP_QUOTE_SELLER_ACCOUNT_CODE`

Do not merge quote tools into `ApplicationServer`. Claude Code will still show `health_check` under `laravel-application`. Quote tools belong under `laravel-quotes`.

## Data

No new tables.

Reuse:

- `mcp_client_tokens` for HTTP Quote MCP (TASK-008).
- `MCP_QUOTE_SELLER_ACCOUNT_CODE` for seller identity on both local stdio and HTTP.
- existing Quote MCP tools and `generate-quote-report` prompt.

## Environment

Local Claude Code stdio does not send `MCP_QUOTE_TOKEN`.

Laravel still needs an active seller:

```dotenv
MCP_QUOTE_SELLER_ACCOUNT_CODE=VEN-000001
```

HTTP clients (Cursor remote MCP, tunnels, non-local Claude) still send:

```http
Authorization: Bearer <raw-mcp-client-token>
```

The raw value must exist as a usable `mcp_client_tokens` row created through the TASK-008 administration API. `MCP_QUOTE_TOKEN_HASH` must not authenticate Quote MCP.

Add a local developer bootstrap so an operator can mint one usable client token without inventing a second auth scheme. The bootstrap must print the raw token once and store only the hash.

## Authentication Flows

```text
Claude Code terminal (local)
      |
      | .mcp.json spawns stdio
      v
docker compose exec -T app php artisan mcp:start quotes
      |
      v
QuoteServer (local handle "quotes")
      |
      |-- APP_ENV is local or testing
      |-- resolve VEN-* Account from MCP_QUOTE_SELLER_ACCOUNT_CODE
      |-- require seller + active
      |-- set request user
      v
search_products / search_customers /
generate_quote_report / get_quote_report
```

```text
HTTP MCP client (unchanged TASK-008)
      |
      | Authorization: Bearer <raw-mcp-client-token>
      v
/mcp/quotes
      |
      v
AuthenticateQuoteMcp
      |
      |-- mcp_client_tokens usable row
      |-- resolve VEN-* Account from MCP_QUOTE_SELLER_ACCOUNT_CODE
      v
QuoteServer
```

## Constraints

### Must

- Keep `Mcp::local('quotes', QuoteServer::class)` and use it as the Claude Code local transport.
- Point project `.mcp.json` `laravel-quotes` at Docker stdio `mcp:start quotes` with `-T`, matching `laravel-application`.
- List these tools on the local quotes server: `search_products`, `search_customers`, `generate_quote_report`, `get_quote_report`.
- Expose prompt `generate-quote-report` on that same local server.
- On local/testing stdio only, set `$request->user()` to the configured active seller Account so `RequiresSellerAccount` succeeds.
- Fail closed on local stdio when `MCP_QUOTE_SELLER_ACCOUNT_CODE` is missing, the Account is missing, not a seller, not `VEN-*`, or inactive.
- Keep `AuthenticateQuoteMcp` on `Mcp::web('/mcp/quotes', QuoteServer::class)`.
- HTTP Quote MCP must still require a usable `mcp_client_tokens` row. Do not fall back to `MCP_QUOTE_TOKEN_HASH`.
- Keep HTTPS-outside-local, throttling, log redaction, and `RequiresSellerAccount`.
- Update README / `.env.example` comments that still tell developers to authenticate Quote MCP with `MCP_QUOTE_TOKEN_HASH`.
- Document that Claude Code `/mcp` → **Tools for laravel-application** is only `health_check`; quote tools are **Tools for laravel-quotes**.
- Add automated tests.

### Must Not

- Do not add quote tools to `ApplicationServer` or `laravel-application`.
- Do not remove `health_check` from the application server.
- Do not authenticate HTTP `/mcp/quotes` without a usable `mcp_client_tokens` row.
- Do not authenticate HTTP `/mcp/quotes` with `MCP_ADMINISTRATION_TOKEN_HASH`.
- Do not treat production `stdio` as an authenticated transport. Auto seller binding is local/testing only.
- Do not persist raw MCP client tokens or the administration token.
- Do not paste raw tokens into committed `.mcp.json` or `.cursor/mcp.json`.
- Do not log `Authorization` headers, raw tokens, or hashes.
- Do not put tokens in query parameters, prompts, or MCP tool arguments.
- Do not disable `RequiresSellerAccount`.
- Do not introduce Sanctum, Passport, OAuth, or `user_id` for this path.

### Out of Scope

- Token management UI.
- Per-token seller Account mapping.
- Per-tool scopes.
- Changing REST quote/product/customer/account resources.
- Changing TASK-008 administration routes.
- Claude.ai browser connectors (they cannot reach `localhost`).

## Current State

The application already has:

- `ApplicationServer` with `health_check` on local handle `application` and web `/mcp`.
- `QuoteServer` with four quote tools and `generate-quote-report` on local handle `quotes` and web `/mcp/quotes`.
- `AuthenticateQuoteMcp` looking up `mcp_client_tokens` (TASK-008).
- seller identity from `MCP_QUOTE_SELLER_ACCOUNT_CODE`.
- Claude Code project file `.mcp.json` with `laravel-application` stdio and `laravel-quotes` HTTP + `MCP_QUOTE_TOKEN`.
- Cursor file `.cursor/mcp.json` with the same quotes HTTP entry plus Laravel Boost.
- README still instructing `MCP_QUOTE_TOKEN_HASH` for Quote MCP auth.

TASK-006 moved the authenticated quote client from stdio to HTTP because stdio never ran `AuthenticateQuoteMcp`. That remains correct for non-local HTTP clients. This task restores stdio **only** for local Claude Code, with an explicit local/testing seller binder so tools are not anonymous.

## Client configuration

### Claude Code (required)

Project file:

```text
.mcp.json
```

`laravel-quotes` must spawn stdio, not HTTP:

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
        },
        "laravel-quotes": {
            "command": "docker",
            "args": [
                "compose",
                "exec",
                "-T",
                "app",
                "php",
                "artisan",
                "mcp:start",
                "quotes"
            ]
        }
    }
}
```

Docker Compose must be running before Claude Code starts the servers. After changing `.mcp.json`, restart MCP in Claude Code (`/mcp`).

Expected picker:

- **Tools for laravel-application** — `health_check`
- **Tools for laravel-quotes** — `search_products`, `search_customers`, `generate_quote_report`, `get_quote_report`

### Cursor (keep HTTP)

`.cursor/mcp.json` may keep HTTP `laravel-quotes` for TASK-008 client-token auth. If HTTP is used locally, the operator must create a usable MCP client token and set `MCP_QUOTE_TOKEN` in the Cursor environment. Do not commit the raw token.

Optional: Cursor may also use the same stdio `laravel-quotes` block as Claude Code for local development. If both transports are present, keep names distinct.

### Local HTTP token bootstrap (optional helper)

Provide an Artisan command usable only in `local` (and tests) that creates one MCP client token the same way the administration API does: generate raw token, store SHA-256 hash, print raw token once.

Example:

```bash
docker compose exec app php artisan mcp:client-token:create --expires-in-days=90
```

Do not seed a raw token into git. Do not create this row from `MCP_QUOTE_TOKEN_HASH`.

## Tasks

### T1: Bind seller identity on local quotes stdio

What: When Quote MCP runs through `php artisan mcp:start quotes` in `local` or `testing`, resolve the configured seller and set the request user.

Suggested files:

- `app/Mcp/Servers/QuoteServer.php` and/or a dedicated local identity concern/middleware used only by the local handle
- `config/mcp.php` if a dedicated flag is cleaner than checking `app()->environment()`

Required behavior:

1. Run only for the local quotes transport, never as a substitute for `AuthenticateQuoteMcp` on HTTP.
2. Read `config('mcp.quotes.seller_account_code')`.
3. Reject when missing/empty.
4. Load the Account; require type seller, `VEN-*`, `active=true`.
5. Set that Account as the authenticated user so `$request->user()` matches HTTP.
6. In `production` (and any non-local, non-testing env), do not auto-bind a seller on stdio.

Verify:

- local quotes tools see the configured seller.
- missing or inactive seller does not run quote tools.
- HTTP still 401 without a usable client token even when the seller config is valid.

### T2: Point Claude Code at local quotes stdio

What: Update `.mcp.json` so `laravel-quotes` uses `docker compose exec -T app php artisan mcp:start quotes`.

Remove the HTTP URL + `MCP_QUOTE_TOKEN` header from the Claude Code project file. That transport is what broke after TASK-008 and is not how Claude Code lists `health_check`.

Verify:

- `.mcp.json` contains no raw secrets.
- `laravel-application` is unchanged (stdio `mcp:start application`).
- documented Claude Code steps match the health-check stdio pattern.

### T3: Keep HTTP Quote MCP on mcp_client_tokens

What: Do not regress TASK-008.

`routes/ai.php` must remain:

```php
Mcp::web('/mcp/quotes', QuoteServer::class)
    ->middleware([
        AuthenticateQuoteMcp::class,
        'throttle:mcp',
    ]);
```

Verify:

- missing bearer → `401`.
- unknown/revoked/expired token → same `401` invalid-token payload.
- usable token still lists the four quote tools over HTTP.
- administration token cannot call `/mcp/quotes`.

### T4: Local client-token create command

What: Give local operators a way to mint an HTTP client token without using a UI.

Suggested:

```bash
php artisan make:command McpClientTokenCreateCommand --no-interaction
```

Requirements:

- available in `local` and `testing` only (fail closed elsewhere).
- `expires_in_days` required, positive integer.
- generate raw token on the server; store hash only; print raw token once to stdout.
- do not print or store `token_hash` unless tests need to assert the hash independently of stdout.

Verify:

- command persists a usable row.
- stdout contains the raw token once.
- command is rejected outside local/testing.

### T5: Correct operator docs

What: Stop telling people that `MCP_QUOTE_TOKEN_HASH` authenticates Quote MCP.

Update:

- `README.md` Quote MCP section
- `.env.example` comments
- `config/mcp.php` comments if they still describe the old static-hash client flow

Document:

1. Claude Code local: `.mcp.json` stdio `mcp:start quotes`; seller from `MCP_QUOTE_SELLER_ACCOUNT_CODE`.
2. HTTP clients: create a token (administration API or local Artisan command), set `MCP_QUOTE_TOKEN` only in the client environment.
3. Claude Code `/mcp` server names: application vs quotes.

Verify:

- no remaining instruction that putting a hash in `.env` is enough for Claude Code to see quote tools.
- no committed raw tokens.

### T6: Add tests

Cover:

Local/stdio identity:

- quotes local server exposes the four tools and `generate-quote-report`.
- with a valid seeded seller, a quote tool can run (for example `search_products` returns catalog data).
- missing seller config or inactive seller fails closed.
- application local server still exposes only `health_check`.

HTTP (regression):

- `/mcp/quotes` without a usable `mcp_client_tokens` row → `401`.
- usable token → tools/list still returns the four quote tools.

Artisan bootstrap:

- local create command stores hash only and outputs the raw token.
- non-local environment refuses the command.

Reuse existing factories (`McpClientToken`, `Account`, products/customers as needed). Use test configuration rather than real credentials.

Verify:

```bash
php artisan test --compact --filter=QuoteMcp
```

and a focused filter for the new local/stdio and Artisan tests.

## Validation

The task is complete when:

1. Claude Code `.mcp.json` starts `laravel-quotes` via `docker compose exec -T app php artisan mcp:start quotes`.
2. After MCP reload, Claude Code `/mcp` shows quote tools under **laravel-quotes**, not only `health_check` under **laravel-application**.
3. Local quote tools run as the configured active `VEN-*` seller.
4. `laravel-application` still has only `health_check`.
5. HTTP `/mcp/quotes` still requires a usable `mcp_client_tokens` row and still rejects `MCP_QUOTE_TOKEN_HASH`-only setup.
6. Local Artisan create command can mint an HTTP client token (hash stored, raw printed once).
7. README and `.env.example` no longer describe `MCP_QUOTE_TOKEN_HASH` as Quote MCP request authentication.
8. Relevant Quote MCP and new local/stdio tests pass.
