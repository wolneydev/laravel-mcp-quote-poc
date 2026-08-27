# MCP Client Token Management

## Why

Quote MCP currently authenticates the web endpoint with a single SHA-256 hash stored in the Laravel environment (`MCP_QUOTE_TOKEN_HASH` from TASK-006). That works for one client, but it cannot issue, expire, or revoke tokens without changing server configuration.

Operators need a first-class module to manage MCP client tokens: store only hashes, set a lifetime in days, revoke a token without deleting history, and keep MCP authentication fail-closed.

Administration of those tokens must not reuse the MCP client bearer token. A separate static administration credential (`MCP_ADMINISTRATION_TOKEN_HASH`) protects the management API.

## What

Create an MCP client token module with:

- a database table that stores token hashes, a revoke flag, an expiration lifetime in days, and timestamps
- REST endpoints to create, list, show, update, and revoke those tokens
- administration authentication using the static environment credential `MCP_ADMINISTRATION_TOKEN_HASH`
- Quote MCP web authentication that accepts a bearer token only when it is present, matches a stored hash, is not revoked, and is not expired

Laravel must never persist the raw MCP client token. The raw value is returned once on create and then lives only with the MCP client.

Keep the TASK-006 seller identity resolution (`MCP_QUOTE_SELLER_ACCOUNT_CODE`) so `$request->user()` remains the configured active seller Account.

## Data

Create an `mcp_client_tokens` table with these fields:

- `id` — primary key.
- `token_hash` — unique SHA-256 hex hash of the raw bearer token.
- `revoked` — boolean, default `false`.
- `expires_in_days` — unsigned integer lifetime in days, measured from `created_at`.
- `created_at` — datetime.
- `updated_at` — datetime.

Do not store the raw token. Do not store a separate `expires_at` column; expiration is derived as `created_at + expires_in_days`.

A token is usable for Quote MCP only when all of the following are true:

1. the request bearer token hashes to a stored `token_hash` (timing-safe compare)
2. `revoked` is `false`
3. `now` is strictly before `created_at + expires_in_days`

## Environment

Administration endpoints use a static bearer token distinct from MCP client tokens.

Laravel stores only the SHA-256 hash:

```dotenv
MCP_ADMINISTRATION_TOKEN_HASH=<sha256-of-the-raw-admin-token>
```

The administration HTTP client keeps the raw secret in its own environment and sends:

```http
Authorization: Bearer <raw-admin-token>
```

Generate a raw administration token with:

```bash
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

Hash it for Laravel:

```bash
php -r "echo hash('sha256', getenv('MCP_ADMINISTRATION_TOKEN')), PHP_EOL;"
```

Read this value through `config()`, not `env()`, in application code. Add a safe empty placeholder to `.env.example`.

Do not reuse `MCP_QUOTE_TOKEN_HASH` or any MCP client token as the administration credential.

`MCP_QUOTE_TOKEN_HASH` is superseded for Quote MCP request authentication by rows in `mcp_client_tokens`. Keep `MCP_QUOTE_SELLER_ACCOUNT_CODE` for seller identity.

## Authentication Flows

```text
Operator / admin client
      |
      | Authorization: Bearer <raw-admin-token>
      v
/api/mcp-client-tokens*
      |
      v
AuthenticateMcpTokenAdministration middleware
      |
      |-- extract bearer token
      |-- sha256(token)
      |-- hash_equals against MCP_ADMINISTRATION_TOKEN_HASH
      |-- fail closed if hash missing or mismatch
      v
MCP client token CRUD
```

```text
LLM / MCP Client
      |
      | Authorization: Bearer <raw-mcp-client-token>
      v
/mcp/quotes
      |
      v
AuthenticateQuoteMcp middleware
      |
      |-- extract bearer token
      |-- sha256(token)
      |-- find mcp_client_tokens row by token_hash
      |-- require revoked = false
      |-- require created_at + expires_in_days > now
      |-- resolve VEN-* Account from MCP_QUOTE_SELLER_ACCOUNT_CODE
      |-- require seller + active
      |-- set request user
      v
QuoteServer
```

## Constraints

### Must

- Store only SHA-256 hashes of MCP client tokens (`hash('sha256', $rawToken)`).
- Unique index on `token_hash`.
- `revoked` must be boolean with default `false`.
- `expires_in_days` must be a positive integer.
- Use Laravel timestamps (`created_at`, `updated_at`).
- Generate the raw MCP client token on the server (at least 32 random bytes). Never accept a caller-supplied raw token to store.
- Return the raw token in the create response only. Later show/index/update responses must omit the raw token and may omit `token_hash`.
- Protect all MCP client token REST endpoints with middleware that authenticates `MCP_ADMINISTRATION_TOKEN_HASH`.
- Fail closed when `MCP_ADMINISTRATION_TOKEN_HASH` is missing or empty.
- Use `hash_equals` for administration and MCP client hash comparison.
- Authenticate Quote MCP (`/mcp/quotes`) against `mcp_client_tokens`, not against `MCP_QUOTE_TOKEN_HASH`.
- A request is authorized for MCP only when the token is valid **and** `revoked = false` **and** it is not expired.
- Missing token, unknown token, hash mismatch, expired token, and revoked token must all return `401` with the same invalid-token payload. Do not reveal whether the secret was unknown, revoked, or expired.
- Keep HTTPS-outside-local, throttling, log redaction, and seller Account resolution from TASK-006.
- Keep `RequiresSellerAccount` as defense in depth.
- Use Form Requests and API Resources if that matches the existing API.
- Add automated tests.

### Must Not

- Do not persist raw MCP client tokens or the raw administration token.
- Do not return `token_hash` in API responses unless a later spec explicitly requires it.
- Do not log `Authorization` headers, raw tokens, or hashes.
- Do not put tokens in query parameters, prompts, or MCP tool arguments.
- Do not authenticate token-management routes with an MCP client token.
- Do not authenticate Quote MCP with `MCP_ADMINISTRATION_TOKEN_HASH`.
- Do not distinguish revoked vs invalid in HTTP status or error message.
- Do not introduce `user_id`.
- Do not add JSON/`metadata` columns.
- Do not use Sanctum, Passport, or OAuth for this module.
- Do not keep Quote MCP authentication solely on `MCP_QUOTE_TOKEN_HASH`.

### Out of Scope

- Token management UI.
- Per-token seller Account mapping (all MCP clients still become the configured `MCP_QUOTE_SELLER_ACCOUNT_CODE`).
- Per-tool scopes.
- Multiple administration tokens.
- Customer authentication through MCP.
- Changing REST quote/product/customer/account resources.

## Current State

The application already has:

- Quote MCP web route in `routes/ai.php`.
- `AuthenticateQuoteMcp` comparing the bearer token to `config('mcp.quotes.token_hash')`.
- seller identity from `config('mcp.quotes.seller_account_code')`.
- REST API resources under `routes/api.php`.
- log redaction for authorization/token keys (TASK-006 / TASK-007).

TASK-006 out of scope included “dynamic token issuance” and “multiple seller tokens.” This task supersedes that limitation for issuance, revocation, and expiration of MCP client tokens. It does not add per-token seller identities.

## REST endpoints

All of these require `Authorization: Bearer <raw-admin-token>` validated against `MCP_ADMINISTRATION_TOKEN_HASH`.

- `GET /api/mcp-client-tokens` — list (pagination; filter `revoked` when that matches existing API style).
- `POST /api/mcp-client-tokens` — create (`expires_in_days` required). Response includes the raw token once.
- `GET /api/mcp-client-tokens/{mcp_client_token}` — show metadata only.
- `PUT/PATCH /api/mcp-client-tokens/{mcp_client_token}` — update `expires_in_days` and/or `revoked`.
- `DELETE /api/mcp-client-tokens/{mcp_client_token}` — set `revoked=true` (soft revoke). Do not delete the row if that would erase audit of the hash; prefer revoke-on-delete.

Create request body:

```json
{
  "expires_in_days": 90
}
```

Create response may include:

```json
{
  "id": 1,
  "token": "<raw-token-once>",
  "revoked": false,
  "expires_in_days": 90,
  "created_at": "...",
  "updated_at": "..."
}
```

Subsequent responses omit `token`.

Missing or invalid administration bearer token returns `401`. Do not leak whether the administration secret was wrong vs unset.

## Tasks

### T1: Add administration token configuration

What: Configure `MCP_ADMINISTRATION_TOKEN_HASH` next to the existing Quote MCP config.

Suggested file:

- `config/mcp.php`

Example:

```php
'administration' => [
    'token_hash' => env('MCP_ADMINISTRATION_TOKEN_HASH'),
],
```

Update `.env.example`:

```dotenv
MCP_ADMINISTRATION_TOKEN_HASH=
```

Verify:

- application code uses `config()`.
- no real secret is committed.

### T2: Create the mcp_client_tokens migration, model, and factory

What: Persist hash, revoke flag, day lifetime, and timestamps.

Files:

- `database/migrations/*_create_mcp_client_tokens_table.php`
- `app/Models/McpClientToken.php`
- factory for tests

Required columns:

- `token_hash` — `string`, unique, 64 characters (SHA-256 hex).
- `revoked` — `boolean`, default `false`.
- `expires_in_days` — unsigned integer.
- timestamps.

Add a model helper such as `isUsable(): bool` that is true only when `revoked` is false and the derived expiration is still in the future.

Verify:

- `php artisan migrate` succeeds.
- rollback succeeds.
- unique index exists for `token_hash`.

### T3: Create administration authentication middleware

What: Authenticate token-management routes with the static administration hash.

Suggested file:

- `app/Http/Middleware/AuthenticateMcpTokenAdministration.php`

Required behavior:

1. Read the bearer token.
2. Return `401` when it is absent.
3. Load `config('mcp.administration.token_hash')`.
4. Fail closed when the configured hash is missing.
5. Compare `hash('sha256', $token)` with `hash_equals`.
6. On success, continue. Do not set an Account user unless a later spec requires it.

Verify:

- valid administration token reaches the controller.
- invalid or missing administration token returns `401`.
- an MCP client token cannot call the administration API.

### T4: Implement MCP client token REST CRUD

What: Controller, form requests, API resource, and routes.

Suggested files:

- `app/Http/Controllers/Api/McpClientTokenController.php`
- Form requests for store/update
- `app/Http/Resources/McpClientTokenResource.php`
- `routes/api.php`

On create:

1. Generate a raw token (`bin2hex(random_bytes(32))` or equivalent).
2. Store `hash('sha256', $rawToken)`.
3. Store `expires_in_days` and `revoked=false`.
4. Return the resource including the raw token once.

On delete: set `revoked=true` rather than physically deleting, unless tests prove a hard delete is required and does not weaken revoke auditing.

Verify:

- unauthenticated calls return `401`.
- create returns the raw token once.
- show/index never return the raw token.
- validation rejects missing or non-positive `expires_in_days`.

### T5: Switch Quote MCP authentication to stored tokens

What: Update `AuthenticateQuoteMcp` so MCP access requires a usable `mcp_client_tokens` row.

Replace comparison against `MCP_QUOTE_TOKEN_HASH` with:

1. compute SHA-256 of the bearer token
2. look up `token_hash`
3. reject when no row exists, `revoked` is true, or the token is expired
4. in every rejection path, return the same `401` invalid-token response
5. on success, resolve the configured seller Account as today

Do not tell the caller “revoked” vs “invalid” vs “expired”.

Verify:

- valid unexpired unrevoked token authenticates the seller.
- unknown token → `401` invalid token.
- revoked token → `401` invalid token (same body).
- expired token → `401` invalid token (same body).
- missing token → `401`.
- administration token cannot call `/mcp/quotes` unless it also exists as a usable MCP client token (it must not).

### T6: Keep seller authorization and existing MCP behavior

What: Preserve `MCP_QUOTE_SELLER_ACCOUNT_CODE`, `RequiresSellerAccount`, throttling, HTTPS-outside-local, and log redaction.

Verify:

- `$request->user()` is still the configured seller Account.
- customer Accounts still cannot use seller-only tools.
- authorization headers remain redacted in logs.

### T7: Add tests

Cover:

Administration API:

- missing administration bearer → `401`.
- invalid administration bearer → `401`.
- missing `MCP_ADMINISTRATION_TOKEN_HASH` config → fail closed.
- MCP client token cannot access administration routes.
- create persists hash only and returns raw token once.
- index/show omit raw token.
- update can set `revoked=true`.
- delete/revoke sets `revoked=true`.
- `expires_in_days` validation.

Quote MCP:

- usable token → authenticated seller; tools still work.
- invalid token → `401` invalid token.
- revoked token → `401` invalid token (identical response to invalid).
- expired token (`created_at` older than `expires_in_days`) → `401` invalid token.
- responses never contain token material.

Use test configuration rather than real credentials.

Verify:

```bash
php artisan test --compact --filter=McpClientToken
```

and existing Quote MCP auth tests still pass after they are updated to seed a usable `mcp_client_tokens` row instead of only `MCP_QUOTE_TOKEN_HASH`.

## Validation

The task is complete when:

1. `mcp_client_tokens` migrates and rolls back with hash, `revoked`, `expires_in_days`, and timestamps.
2. Administration routes are reachable only with `MCP_ADMINISTRATION_TOKEN_HASH`.
3. Creating a token stores a SHA-256 hash and returns the raw secret only once.
4. Quote MCP accepts a bearer token only when it matches a hash, `revoked` is `false`, and the day lifetime has not elapsed.
5. Invalid tokens and revoked tokens both return the same `401` invalid-token response.
6. Expired tokens are rejected with that same invalid-token response.
7. `MCP_ADMINISTRATION_TOKEN_HASH` cannot be used as a Quote MCP client token.
8. MCP client tokens cannot be used as the administration credential.
9. Laravel stores no raw tokens.
10. Seller identity for MCP tools remains the configured `VEN-*` Account.
11. Relevant MCP client token and Quote MCP tests pass.
