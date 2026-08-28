## From an LLM client (Claude, Codex, …)

Quote work lives on the **laravel-quotes** MCP server. Docker Compose must be up, migrations seeded, and `MCP_QUOTE_SELLER_ACCOUNT_CODE` set to an active seller (seeder default `VEN-000001`). Slash commands below are **MCP prompts**, not HTTP routes. Never paste bearer tokens into this page, into prompts, or into committed JSON.

### Claude Code (local stdio)

Project file `.mcp.json` at the repo root should spawn stdio (this is the committed layout):

```json
{
  "mcpServers": {
    "laravel-application": {
      "command": "docker",
      "args": ["compose", "exec", "-T", "app", "php", "artisan", "mcp:start", "application"]
    },
    "laravel-quotes": {
      "command": "docker",
      "args": ["compose", "exec", "-T", "app", "php", "artisan", "mcp:start", "quotes"]
    }
  }
}
```

1. `docker compose up -d` then `php artisan migrate --seed` inside the app container.
2. Restart MCP in Claude Code (`/mcp`).
3. Use **Tools for laravel-quotes** for quotes. **Tools for laravel-application** is only `health_check`.
4. Local stdio does **not** send `MCP_QUOTE_TOKEN`. Seller identity is `MCP_QUOTE_SELLER_ACCOUNT_CODE`.

Example after connect: `/generate-quote-from-notes` and paste a visit note, or `/generate-quote-report` and name a catalog customer plus quantities.

### Codex (OpenAI Codex CLI)

Same local process as Claude Code. In Codex `config.toml` (path depends on your Codex install, often `~/.codex/config.toml`):

```toml
[mcp_servers.laravel-application]
command = "docker"
args = ["compose", "exec", "-T", "app", "php", "artisan", "mcp:start", "application"]

[mcp_servers.laravel-quotes]
command = "docker"
args = ["compose", "exec", "-T", "app", "php", "artisan", "mcp:start", "quotes"]
```

Run Codex from this project directory so `docker compose exec` sees the same Compose file. Reload MCP after changing the config.

**HTTP instead of stdio** (Cursor, remote Codex, tunnels): `POST` JSON-RPC to `http://localhost:8890/mcp/quotes`. Mint a token once (Laravel stores only the hash; the raw value prints once):

```bash
docker compose exec app php artisan mcp:client-token:create --expires-in-days=90
```

Put the raw value in the **client environment** as `MCP_QUOTE_TOKEN`. Example Cursor `.cursor/mcp.json` (do not commit the secret):

```json
{
  "mcpServers": {
    "laravel-quotes": {
      "url": "http://localhost:8890/mcp/quotes",
      "headers": {
        "Authorization": "Bearer ${env:MCP_QUOTE_TOKEN}"
      }
    }
  }
}
```

A hash in `.env` does not authenticate Quote MCP HTTP.

## Available slash prompts and tools

### Slash prompts (`laravel-quotes`)

| Name | Description |
| --- | --- |
| `/generate-quote-report` | Conversational collect: search until one customer and products, then persist a draft. Does not approve. |
| `/generate-quote-from-notes` | Ingest a notes file or paste, then the same search → persist path. Ignore note prices. |
| `/generate-quote-draft-pdf` | After a stored `QUO-*`, ask Laravel to write a private PDF. Does not approve. |

### Tools (`laravel-quotes`)

| Name | Description |
| --- | --- |
| `search_products` | Active catalog by name or `PROD-*` (capped result set). |
| `search_customers` | Quote-ready customer + `CLI-*` by name, `CUST-*`, or document input. |
| `ingest_seller_quote_notes` | UTF-8 notes or private `quotes/notes/` path → briefing. Does not write a quote. |
| `generate_quote_report` | Persist a **draft** from codes and quantities. Laravel snapshots catalog prices. No caller prices. |
| `get_quote_report` | Reload a stored report by id or `QUO-*` (persisted money). |
| `generate_quote_draft_pdf` | Render and save a private PDF for an existing quote. |

### Tools (`laravel-application`)

| Name | Description |
| --- | --- |
| `health_check` | Confirms the application MCP process is up. Not a quote tool. |

## Future improvements

**In-app LLM API (for example OpenAI).** This PoC does not call an LLM from Laravel. Upload uses a briefing parser plus catalog lookup; Claude/Codex call MCP tools from the client.

A later iteration could: store the notes file privately as today, send a minimized notes payload to an LLM HTTP API, then drive MCP (`ingest_seller_quote_notes` → `search_customers` / `search_products` → `generate_quote_report`) so a draft appears automatically. Prices and `QUO-*` numbers would still come from Laravel. Approval would stay a human step. Vendor API keys would live in the environment, never on this page. That work is not implemented here.
