# Proof of concept

This project is a small B2B quote domain with two entry points that share one engine. REST is the operational API. MCP is the conversational API for an authenticated seller. Both persist quotes through `CreateQuoteAction` and `QuotePricingService`. The LLM looks up entities and requests persistence; it does not price, approve, or generate documents.

Stack: Laravel 13, PHP 8.3, PostgreSQL 16, Docker Compose (HTTP **8890**, Postgres **5439**). Laravel MCP owns application tools; Laravel Boost is for local agent-assisted development.

Source and clone: [github.com/wolneydev/laravel-mcp-quote-poc](https://github.com/wolneydev/laravel-mcp-quote-poc).

---

## Problem

Sellers describe quotes in natural language. An LLM can collect that input only if the application stays the source of truth:

- Callers never send `unit_price`, `line_total`, or `total`. Prices come from the catalog and are snapshotted on line items.
- Name lookup must yield exactly one quote-ready match. Ambiguous results return candidates and do not persist.
- Report generation and PDF save leave status as `draft`. Approval is a workflow transition.
- MCP payloads omit tax documents and contact PII. REST may still return those fields for operators.
- Bearer tokens live in the client environment / `Authorization` header, never in prompts or tool arguments.
- PDFs are rendered in Laravel from stored snapshots. Bytes are not put in model-facing tool text.

---

## Build order

Later layers assume a stable domain. The LLM is not attached to an undefined quote.

| Task | What it added |
| --- | --- |
| 001 Products | `PROD-*` catalog, decimal prices |
| 002 Customers | `CUST-*` profiles, no passwords |
| 003 Accounts | Authenticatable `accounts`: `VEN-*` sellers, `CLI-*` customers (1:1) |
| 004 Quotes | Header + snapshot lines, transaction, lifecycle |
| 005 MCP reports | Tools + `/generate-quote-report`; same create path as REST |
| 006 Static bearer | HTTP MCP: hashed env token, configured seller |
| 007 Vendor privacy | Minimize what tool results send to a third-party LLM |
| 008 Client tokens | Issuable, expirable, revocable hashed tokens; separate admin credential |
| 009 Local stdio | Claude Code quote tools over stdio; HTTP still fail-closed |
| 010 Money on drafts | Show persisted money; still forbid sending prices |
| 011 Draft PDF | DomPDF from snapshots |
| 012 Private save | After the draft, ask; write under `storage/app/private` |
| 013 Notes ingest | Welcome upload + notes file → briefing → existing generate/PDF tools |

---

## Domain

Public codes are the contract. Internal ids stay inside the application.

```text
PROD-*   product
CUST-*   customer profile (not a login)
VEN-*    seller account
CLI-*    customer account (1:1 with a customer)
QUO-*    quote number
```

`customers` holds business data. Credentials live on `accounts`. A customer is quote-ready only when the profile is active and an active `CLI-*` account exists.

A quote binds two accounts: active seller (`customer_id` null) and active customer (with `customer_id`). Same account cannot play both roles. Mixed currencies and duplicate products are rejected.

Each `quote_item` copies code, name, unit, and `unit_price` at create time. `line_total = quantity × unit_price`. `quotes.total` is the sum. Catalog price changes do not rewrite history. Reports and PDFs must not reprice from live `products.price`.

```text
draft → pending_approval → approved | rejected
```

Only drafts can change lines. Generating a report or a PDF is neither submit nor approve.

---

## Architecture

```text
REST  →  /api/*        →  CreateQuoteAction  →  QuotePricingService  →  PostgreSQL
MCP   →  /mcp/quotes   ↗
          (or local stdio)
```

- REST (`routes/api.php`): CRUD for products, customers, accounts; quote create / edit / submit / approve / reject; optional signed GET for draft PDF download.
- MCP (`routes/ai.php`): `QuoteServer` at `/mcp/quotes`. `/mcp` is health-check only. Quote tools are not mixed into that server.

`QuotePricingService` computes currency, snapshots, and totals (mixed currency fails). `CreateQuoteAction` writes in one transaction, including concurrency-safe `QUO-YYYY-…` numbers. Controllers and tools resolve and authorize; they do not reimplement arithmetic. Tables have no JSON metadata columns; the schema is the contract.

---

## MCP

Tools are the only mutating/rendering API. Prompts tell the model how to use them. Slash commands are not HTTP routes.

| Name | Kind | Role |
| --- | --- | --- |
| `search_products` | Tool | Active catalog by name/code (max 10) |
| `search_customers` | Tool | Customer + `CLI-*`; document is input only |
| `generate_quote_report` | Tool | Resolve, validate, persist draft, return report |
| `get_quote_report` | Tool | Load by id or `QUO-*`; read stored money |
| `generate_quote_draft_pdf` | Tool | Render and write private PDF; no approve/reprice |
| `ingest_seller_quote_notes` | Tool | Notes file or pasted UTF-8 → briefing; no quote write |
| `generate-quote-report` | Prompt | Collect → search → generate → show money → ask about PDF |
| `generate-quote-draft-pdf` | Prompt | PDF for an existing `QUO-*` |
| `generate-quote-from-notes` | Prompt | Ingest notes → search → same generate/PDF-ask path |

Flow: `/generate-quote-report` → search until unambiguous → `generate_quote_report` with codes and quantities only → copy persisted money into the draft → ask whether to save a PDF → on yes, `generate_quote_draft_pdf` writes `storage/app/private/quotes/drafts/{quote_number}-draft.pdf`.

Notes path: seller uses the PoC landing on `GET /`. Wide viewports show two columns: left is the notes path (steps, `.txt` template, upload); right is how to connect Claude Code or Codex, plus slash prompts and tools. Upload stores the file privately, ingests a briefing, and when matches are unique creates a draft with `CreateQuoteAction`. An **Approve** button then runs `SubmitQuoteAction` + `ApproveQuoteAction`. MCP `ingest_seller_quote_notes` remains briefing-only. There is no MCP approve tool. Note prices and obvious PII are not used as quote prices or shown from uploaded files. OCR of photos or scans is out of scope. Calling an LLM HTTP API (OpenAI and similar) from Laravel to parse notes and drive MCP is documented as future work only.

---

## Authentication

Quote tools require `$request->user()` to be a seller `Account`. Middleware authenticates; `RequiresSellerAccount` still authorizes.

**HTTP** (`POST /mcp/quotes`): usable row in `mcp_client_tokens` (SHA-256 only; raw token returned once on create). Usable if hash matches, not revoked, and not expired. Unknown, revoked, and expired all return the same 401. Identity is the configured active `VEN-*` (`MCP_QUOTE_SELLER_ACCOUNT_CODE`). Token administration uses a separate `MCP_ADMINISTRATION_TOKEN_HASH`. The env hash `MCP_QUOTE_TOKEN_HASH` no longer authenticates this route.

Tokens prove the client, not a per-seller OAuth user. Per-token seller mapping and scopes are out of scope. Throttling, HTTPS outside local, and log redaction of `Authorization` apply.

**Local stdio** (`php artisan mcp:start quotes`): no bearer header. In `local`/`testing` only, seller is bound from `MCP_QUOTE_SELLER_ACCOUNT_CODE`. Production stdio does not auto-bind. HTTP remains fail-closed on `mcp_client_tokens`.

No Sanctum, Passport, or OAuth on Quote MCP.

---

## Privacy

Tool results typically go into the LLM vendor conversation. Local stdio and HTTP have the same exposure once the client forwards them. The application cannot enforce vendor retention or training terms. It can minimize payloads.

Emitted: public codes, display names, quantities, persisted money, optional `document_present`, PDF metadata (not bytes), notes briefings (mentions and sanitized remainder).

Withheld: full `document`, email, phone, contact name, passwords, tokens, inbound note prices, raw notes bodies in MCP text. Document remains a search input; it is not echoed. Same tables, different disclosure by channel.

---

## Money and PDF

Do not send prices into `generate_quote_report`. Do show persisted `unit_price`, `line_total`, `total`, and `currency` on the draft. Missing money fields fail closed; the model must not invent them.

The PDF is Blade + DomPDF from `QuoteReportPresenter`, authorized with `QuotePolicy::view`. Files go to the private `local` disk, not `public/` and not `storage:link`. After generate, the prompt must ask before calling the PDF tool. Quote create and PDF save are two calls. Email is out of scope.

---

## Out of scope

Taxes, shipping, inventory, variants, CRM, quote versioning, orders, LLM auto-approval, `user_id`, JSON metadata columns. OCR / vision of handwritten or photographed notes is out of scope; ingest is UTF-8 `.txt` / `.md` only.

Possible later work: per-token seller mapping, tool scopes, customer-facing MCP, OAuth-class credentials, tax/shipping as domain services.
