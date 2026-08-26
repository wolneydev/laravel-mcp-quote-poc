# MCP Static Bearer Token Authentication

## Why

The Quote MCP server currently works through two possible transports:

- local `stdio` using `php artisan mcp:start quotes`
- web/HTTP through the MCP route registered in `routes/ai.php`

The quote tools require `$request->user()` to resolve to an authenticated seller `Account`.

The current local `stdio` connection has no authenticated HTTP request, so the middleware attached to the web MCP route cannot populate `$request->user()`.

The goal of this task is to allow an LLM/MCP client to securely call the Quote MCP web endpoint using a fixed bearer token without exposing credentials in prompts or tool payloads.

The Laravel server must store only a hash of the token. The MCP client must keep the raw token in its own environment and send it through the `Authorization` header.

## What

Add a static bearer-token authentication mechanism for the Quote MCP web server.

Use:

- one random raw token known only by the MCP client.
- one SHA-256 hash of that token stored in the Laravel environment.
- one configured seller Account code representing the MCP client identity.
- one custom middleware that:
  1. reads the bearer token.
  2. hashes it.
  3. compares it with the configured hash using a timing-safe comparison.
  4. resolves the configured seller Account.
  5. validates that the Account is an active seller.
  6. sets that Account as the authenticated request user.

The MCP client must connect to the Quote MCP **web/HTTP endpoint**, not the local `stdio` server, for this authenticated flow.

## Environment

Add server-side configuration similar to:

```dotenv
MCP_QUOTE_TOKEN_HASH=<sha256-of-the-raw-token>
MCP_QUOTE_SELLER_ACCOUNT_CODE=VEN-000001
```

The MCP client / LLM runtime must receive the raw secret through its own environment:

```dotenv
MCP_QUOTE_TOKEN=<raw-random-secret>
```

The raw token must not be stored in prompts, source code, committed configuration files, logs, reports, or MCP tool arguments.

A token may be generated with:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

The corresponding SHA-256 hash may be generated with:

```bash
php -r "echo hash('sha256', getenv('MCP_QUOTE_TOKEN')), PHP_EOL;"
```

## Authentication Flow

```text
LLM / MCP Client
      |
      | Authorization: Bearer <raw-token>
      v
/mcp/quotes
      |
      v
AuthenticateQuoteMcp middleware
      |
      |-- extract bearer token
      |-- sha256(token)
      |-- compare using hash_equals
      |-- resolve VEN-* Account
      |-- require seller + active
      |-- set request user
      v
QuoteServer
      |
      v
SearchProductsTool
SearchCustomersTool
GenerateQuoteReportTool
GetQuoteReportTool
      |
      v
$request->user() === authenticated seller Account
```

## Constraints

### Must

- Keep the Quote MCP server registered as a web server in `routes/ai.php`.
- Authenticate requests through `Authorization: Bearer <token>`.
- Store only the SHA-256 token hash in the Laravel `.env`.
- Store the raw token only in the MCP client's secret/environment configuration.
- Read environment values through a Laravel config file.
- Add safe placeholders to `.env.example`.
- Use `hash_equals` for the final hash comparison.
- Return `401` when the bearer token is missing or invalid.
- Fail closed when the configured token hash is missing.
- Resolve the authenticated identity through `MCP_QUOTE_SELLER_ACCOUNT_CODE`.
- The configured Account must:
  - exist.
  - have `type=seller`.
  - have a `VEN-*` code.
  - have `active=true`.
- Set the resolved Account as the current request user so existing `$request->user()` code keeps working.
- Reuse the existing `Account` authenticatable model.
- Keep `RequiresSellerAccount` checks in place as defense in depth.
- Keep throttling enabled on the MCP route.
- Configure the MCP client to use the HTTP/web MCP transport for `/mcp/quotes`.
- Configure the MCP client to inject the raw bearer token from its environment.
- Add automated authentication tests.

### Must Not

- Do not expose the raw token in an LLM prompt.
- Do not send the token as an MCP tool argument.
- Do not put the token in query parameters.
- Do not log the `Authorization` header.
- Do not return the token or hash in MCP responses.
- Do not hardcode the token in PHP.
- Do not hardcode seller database IDs.
- Do not introduce `user_id`.
- Do not remove existing seller authorization checks.
- Do not treat `stdio` as an authenticated HTTP transport.
- Do not keep the report-generation MCP client configured only as `php artisan mcp:start quotes`.
- Do not use the stored hash as the bearer credential when the client can safely keep the original raw token.
- Do not create a separate login endpoint only to exchange this token for another credential.

### Out of Scope

- OAuth 2.1.
- Laravel Passport.
- Sanctum token management.
- Multiple MCP identities.
- Multiple seller tokens.
- Dynamic token issuance.
- Token management UI.
- Customer authentication through MCP.
- Per-tool scopes.

## Current State

The application already has:

- Products.
- Customers.
- Accounts.
- Quotes.
- Quote MCP tools.
- a Quote MCP server.
- a web MCP route in `routes/ai.php`.
- seller validation through `RequiresSellerAccount`.
- `Account` as the application identity model.

The current local MCP configuration starts the server using:

```text
php artisan mcp:start quotes
```

This is a local `stdio` transport. It does not pass through the HTTP authentication middleware attached to the Quote MCP web route.

Laravel MCP web servers can use normal Laravel middleware, including custom bearer-token authentication.

## Tasks

### T1: Add MCP quote authentication configuration

What: Add application configuration for the token hash and seller account code.

Suggested file:

- `config/mcp.php` or the existing MCP configuration file.

Example:

```php
return [
    'quotes' => [
        'token_hash' => env('MCP_QUOTE_TOKEN_HASH'),
        'seller_account_code' => env('MCP_QUOTE_SELLER_ACCOUNT_CODE'),
    ],
];
```

Update `.env.example`:

```dotenv
MCP_QUOTE_TOKEN_HASH=
MCP_QUOTE_SELLER_ACCOUNT_CODE=VEN-000001
```

Verify:

- application code uses `config()`.
- no real secret is committed.

### T2: Create AuthenticateQuoteMcp middleware

What: Create middleware responsible for authenticating the static bearer token.

Suggested file:

- `app/Http/Middleware/AuthenticateQuoteMcp.php`

Required behavior:

1. Read the bearer token using Laravel's request helper.
2. Return `401` when the token is absent.
3. Load the configured token hash.
4. Fail closed when the configured hash is missing.
5. Compute:

```php
$computedHash = hash('sha256', $token);
```

6. Validate using:

```php
hash_equals($expectedHash, $computedHash)
```

7. Resolve the Account by the configured public code.
8. Require:
   - `type=seller`
   - `active=true`
   - a `VEN-*` code.
9. Set the Account as the current request user.

The implementation may use Laravel authentication facilities or the request user resolver, provided that:

```php
$request->user()
```

returns the resolved `Account` inside MCP tools.

Verify:

- valid token resolves the configured seller.
- invalid token never resolves a user.
- inactive seller is rejected.

### T3: Apply middleware to the Quote MCP web server

What: Protect the Quote MCP endpoint in `routes/ai.php`.

Conceptual example:

```php
Mcp::web('/mcp/quotes', QuoteServer::class)
    ->middleware([
        AuthenticateQuoteMcp::class,
        'throttle:mcp',
    ]);
```

Adapt middleware registration and throttling to the current Laravel application.

If a session-oriented `auth` middleware currently blocks this token-based flow, replace it for this specific MCP route instead of stacking unrelated authentication mechanisms.

Verify:

- request without a token returns `401`.
- valid token reaches QuoteServer.
- `$request->user()` is the configured seller Account.

### T4: Keep seller authorization inside the tools

What: Preserve `RequiresSellerAccount` or equivalent authorization logic.

The middleware authenticates the request identity. Tool-level checks still authorize that identity.

Verify:

- customer Accounts cannot use seller-only tools.
- inactive sellers cannot generate reports.

### T5: Change the MCP client from stdio to web/HTTP

What: Update the MCP client configuration used by the LLM.

The quote-generation server must stop relying only on:

```text
php artisan mcp:start quotes
```

The authenticated client must connect to the Quote MCP web endpoint, for example:

```text
https://<application-host>/mcp/quotes
```

or the equivalent local development URL.

The MCP client must send:

```http
Authorization: Bearer <raw-token>
```

The raw token must come from its environment:

```text
MCP_QUOTE_TOKEN
```

Use the MCP client's supported secret or environment-variable mechanism. Do not paste the raw token into a committed `.mcp.json`.

Verify:

- the LLM can list the Quote MCP tools through HTTP.
- the token is sent automatically by the MCP client.
- the LLM does not need to know or mention the token in the user prompt.

### T6: Keep the report prompt free of authentication secrets

What: Authentication must be transparent to the LLM prompt.

Example:

```text
/generate-quote-report

Create a quote report for Northwind Industrial Ltd using seller account VEN-000001.

Include:
- 4 × Access Doors and Panels
- 6 × Ladders
- 12 × Safety Rails
- 8 × Vent Covers

Use the current system data and prices and return the quote report for approval.
```

The prompt must not contain:

- bearer token.
- token hash.
- password.
- internal database IDs.

Verify:

- the prompt succeeds when the MCP client is correctly authenticated.
- it fails safely when the client has no valid bearer token.

### T7: Add authentication tests

Cover:

- missing bearer token → `401`.
- invalid bearer token → `401`.
- valid bearer token → authenticated seller Account.
- missing server-side hash → fail closed.
- missing configured seller → failure.
- configured customer Account → failure.
- inactive seller → failure.
- seller with invalid/non-`VEN-*` code → failure.
- valid seller can call `search_products`.
- valid seller can call `search_customers`.
- valid seller can call `generate_quote_report`.
- invalid token cannot create a Quote.
- MCP responses never contain token material.

Use test configuration rather than real credentials.

### T8: Add security protections

Required:

- keep MCP throttling enabled.
- redact `Authorization` headers from logs and traces.
- avoid logging complete MCP request headers.
- require HTTPS outside local development.
- use at least 32 random bytes for the raw token.
- fail closed on incomplete authentication configuration.
- document a manual rotation procedure:
  1. generate a new raw token.
  2. compute its SHA-256 hash.
  3. update `MCP_QUOTE_TOKEN_HASH` on the Laravel server.
  4. update `MCP_QUOTE_TOKEN` in the MCP client environment.
  5. refresh Laravel configuration cache.
  6. remove the old client secret.

Verify:

- no authentication secret appears in logs during successful or failed tests.

## Validation

The task is complete when:

1. The Quote MCP server is available through its web/HTTP endpoint.
2. Missing bearer tokens return `401`.
3. Invalid bearer tokens return `401`.
4. The correct raw token authenticates the configured `VEN-*` Account.
5. `$request->user()` inside MCP tools returns that seller Account.
6. `RequiresSellerAccount` continues to work.
7. `search_products` works through the authenticated connection.
8. `search_customers` works through the authenticated connection.
9. `generate_quote_report` can persist a Quote through the authenticated connection.
10. `/generate-quote-report` requires no authentication secret in its prompt.
11. Laravel stores only the token hash.
12. The MCP client receives the raw token only from secret/environment configuration.
13. The authenticated quote flow no longer depends on local `stdio`.
14. Invalid credentials cannot read or create Quote data.
15. Secrets are absent from source control, logs, prompts, tool payloads, and MCP responses.
16. Relevant MCP and Quote tests pass.
