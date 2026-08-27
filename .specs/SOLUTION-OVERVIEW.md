# How this quote system works

This project is a small sales domain with two doors into the same house.

One door is a REST API. Sellers and operators can manage products, customers, login accounts, and quotes through ordinary HTTP endpoints.

The other door is MCP. An LLM client talks to a quote server, looks up customers and products by name or public code, and asks the application to persist a quote report. That report is meant for a human to review and approve. Generating a report does **not** approve the quote.

Both doors create quotes the same way. Controllers and MCP tools do not invent their own math. They call `CreateQuoteAction`, which uses `QuotePricingService`, writes the quote and its items in a single database transaction, and snapshots product names, units, and prices onto each line. If the catalog price changes tomorrow, yesterday’s quote still shows what was actually offered.

There is no `users` table in this flow. `accounts` is the identity table. Sellers log in (or authenticate over MCP) as `VEN-*` accounts. Customers have a business profile in `customers` and, when they can log in, a matching `CLI-*` account.

---

## The pieces, in the order they were built

The work was split into six modules. Each one is useful on its own, but the later ones assume the earlier ones exist.

**Products** are a tiny catalog. A product has a stable public code (`PROD-000001`), a name, a unit, a decimal price, a three-letter currency, and an active flag. Search works by name or code because a seller (or an LLM) rarely starts with a database id.

**Customers** are business profiles, not logins. A customer has a public code (`CUST-000001`), a name, optional document and contact fields, and an active flag. Passwords do not live here. Search works by name, code, or document so MCP can resolve what a seller typed.

**Accounts** are the people who can authenticate. A seller account has type `seller`, a `VEN-*` code, and no customer link. A customer account has type `customer`, a `CLI-*` code, and exactly one customer. Email is unique and lowercase. Passwords are hashed and never returned by the API. Account age in days is calculated from `created_at`; it is not stored.

**Quotes** are the commercial document. A quote always points at two accounts: the seller who owns it and the customer who should approve it. Status moves in a straight line: `draft` → `pending_approval` → `approved` or `rejected`. Only drafts can still change products and quantities.

**MCP quote reports** are a conversation-friendly way to create and reread those quotes. The LLM is guided by a prompt (`/generate-quote-report`). The prompt never prices anything. Tools search the catalog, resolve public codes, then call the same create-quote action as REST.

**Static bearer tokens** exist because an LLM client talking over HTTP is not a logged-in browser session. The client sends a raw token in `Authorization`. Laravel stores only the SHA-256 hash, checks it in constant time, and attaches a configured active seller account to the request. Tools still check that the user is a seller.

**Vendor privacy** is a constraint on the MCP door, not a second quoting system. Tool results go to the MCP client and then into the LLM vendor conversation. Laravel withholds tax documents and contact PII from those payloads, redacts them from default logs, and still prices quotes from the database. Retention, training, and data-processing terms are contractual/client configuration; the application cannot enforce them on the vendor.

Seeders for products, customers, and accounts are idempotent: they `upsert` on public `code`, so you can seed twice without duplicates.

---

## Domain map

Public codes are the language humans and models should use. Internal ids stay inside the application.

```text
PROD-*   product in the catalog
CUST-*   customer profile (company / person)
VEN-*    seller account (login identity)
CLI-*    customer account (login identity, 1:1 with a customer)
QUO-*    persisted quote number
```

How those records connect:

```mermaid
flowchart LR
    subgraph Catalog
        Product["Product<br/>PROD-*"]
    end

    subgraph People
        Customer["Customer<br/>CUST-*"]
        SellerAcc["Seller Account<br/>VEN-*"]
        CustAcc["Customer Account<br/>CLI-*"]
        Customer -->|"one optional login"| CustAcc
    end

    subgraph QuoteDoc["Quote QUO-*"]
        Quote["Quote header<br/>seller + customer + total"]
        Item["Quote items<br/>snapshots of product + price"]
        Quote --> Item
    end

    SellerAcc -->|"seller_account_id"| Quote
    CustAcc -->|"customer_account_id"| Quote
    Product -->|"product_id + copied scalars"| Item
```

A customer account cannot sell. A seller account cannot be the customer on a quote. Seller and customer on one quote cannot be the same account. Mixed currencies on one quote are rejected. Duplicate products on one request are rejected.

---

## Quote lifecycle

A new quote starts as a draft. Submit is the seller asking for a decision. Approve and reject are the customer-side decisions. Historical money comes from columns on `quote_items`, never from a live catalog lookup.

```mermaid
stateDiagram-v2
    [*] --> draft: create quote
    draft --> draft: edit products / quantities
    draft --> pending_approval: submit
    pending_approval --> approved: approve
    pending_approval --> rejected: reject
    approved --> [*]
    rejected --> [*]
```

Creation is one transaction:

1. Validate seller, customer, products, quantities, and currency.
2. Generate a unique quote number (`QUO-YYYY-…`) in a concurrency-safe way.
3. Copy current product price, code, name, and unit onto each line.
4. Compute `line_total = quantity × unit_price` and `quote.total = sum(line totals)`.
5. Persist the header and all items together. If any item fails, nothing is kept.

Callers never supply `unit_price`, `line_total`, or `total`. The application is the source of truth for money.

---

## Two doors, one quote engine

REST lives in `routes/api.php`. MCP lives in `routes/ai.php`. Quote math lives in the domain layer.

```mermaid
flowchart TB
    subgraph Clients
        RESTClient["HTTP / REST client"]
        LLM["LLM + MCP client"]
    end

    subgraph Laravel
        API["Quote API<br/>/api/quotes"]
        MCP["Quote MCP<br/>/mcp/quotes"]
        Action["CreateQuoteAction"]
        Pricing["QuotePricingService"]
        DB[(PostgreSQL)]
    end

    RESTClient --> API
    LLM --> MCP
    API --> Action
    MCP --> Action
    Action --> Pricing
    Action --> DB
```

That split is the main design rule. MCP is not a second quoting system. It is a lookup-and-report front end on top of the same persistence.

There is also a small general MCP server at `/mcp` (health check). Quote work goes to `/mcp/quotes`.

---

## How a seller talks to MCP

The user-facing command is `/generate-quote-report`. That is an MCP **prompt**, not an HTTP route and not the tool that writes the database.

The model is supposed to collect missing pieces, search when a name is ambiguous, and only then call `generate_quote_report`. If two customers share a similar name, the tools return candidates. They do not guess.

```mermaid
flowchart TD
    User["Seller in the LLM client"] --> Prompt["Prompt: generate-quote-report<br/>slash command /generate-quote-report"]

    Prompt --> Gather["Collect seller, customer,<br/>products, quantities"]

    Gather --> SearchC["Tool: search_customers"]
    Gather --> SearchP["Tool: search_products"]

    SearchC --> Ambiguous{Exactly one<br/>quote-ready match?}
    SearchP --> Ambiguous

    Ambiguous -->|no| Ask["Ask the user or show candidates.<br/>Do not create a quote."]
    Ambiguous -->|yes| Generate["Tool: generate_quote_report"]

    Generate --> Authz["Authenticated seller must match<br/>the requested VEN-* account"]
    Authz --> Action["CreateQuoteAction + QuotePricingService"]
    Action --> Saved["Quote saved as draft<br/>status is not approved"]
    Saved --> Report["Structured report + approval_summary"]

    Report --> Later["Later: get_quote_report<br/>by quote id or QUO-* number"]
    Later --> Snapshot["Same persisted prices.<br/>Catalog changes do not rewrite history."]
```

Typical inputs the model (or the tool) can accept:

- Seller: `VEN-000001` (or inferred from the authenticated MCP user).
- Customer: `CUST-*`, `CLI-*`, or a unique name.
- Product: `PROD-*` or a unique name.
- Quantity greater than zero per line.
- Optional `valid_until` and `notes`.

A customer is quote-ready only when the profile is active **and** it has an active customer account. Searching customers through MCP returns that account code so the rest of the flow can stay on public identifiers.

---

## Authentication for the quote MCP

Local Claude Code uses stdio (`php artisan mcp:start quotes` from `.mcp.json`). In `local` and `testing` only, that process binds `$request->user()` from `MCP_QUOTE_SELLER_ACCOUNT_CODE`. Production stdio does not auto-bind a seller.

HTTP clients (`POST /mcp/quotes`) still require a usable `mcp_client_tokens` row. `MCP_QUOTE_TOKEN_HASH` does not authenticate Quote MCP.

The Laravel app never stores the raw HTTP token. The MCP client never puts the token in the prompt or in tool arguments.

```mermaid
sequenceDiagram
    participant Client as HTTP MCP client
    participant MW as AuthenticateQuoteMcp
    participant Tokens as mcp_client_tokens
    participant Acc as Seller Account
    participant Tools as Quote tools

    Client->>MW: POST /mcp/quotes<br/>Authorization: Bearer raw-token
    MW->>MW: SHA-256 of the bearer token
    MW->>Tokens: usable unrevoked unexpired row
    alt missing, unknown, revoked, or expired token
        MW-->>Client: 401
    else usable row
        MW->>Acc: active VEN-* seller from MCP_QUOTE_SELLER_ACCOUNT_CODE
        alt not an active VEN-* seller
            MW-->>Client: 401
        else
            MW->>Tools: request user = that Account
            Tools-->>Client: search / generate / get report
        end
    end
```

On the server: `MCP_QUOTE_SELLER_ACCOUNT_CODE` and hashed rows in `mcp_client_tokens`.

On HTTP clients: `MCP_QUOTE_TOKEN` (the raw secret), minted via the administration API or `php artisan mcp:client-token:create`.

Middleware authenticates HTTP. Local stdio binds the configured seller. `RequiresSellerAccount` still authorizes. Throttling stays on the HTTP route. A customer account configured as the MCP identity is rejected. An inactive seller is rejected.

---

## Quote MCP and the LLM vendor (privacy)

Quote MCP is a third-party LLM data path. After Laravel returns a tool result, the MCP client typically includes that result in the conversation sent to the AI vendor. Local `stdio` (`php artisan mcp:start quotes`) and the authenticated web endpoint (`/mcp/quotes`) have the same payload exposure once the client forwards tool results to the model.

Laravel authenticates the client and minimizes what the model sees. It cannot see, limit, or delete what the vendor retains. Retention, training, evaluation, and data residency are contractual and client-configuration duties, not application runtime checks.

### Fields allowed to reach the LLM

- Customer search / ambiguous candidates: `customer_id`, `customer_code`, `customer_name`, `customer_account_id`, `customer_account_code`, `active`, and optional `document_present` (boolean only).
- Product search: identity (`id`, `code`, `name`), `unit`, catalog `price`, `currency`, `active`. No product `description`.
- Quote reports (structured and Markdown): public codes (`QUO-*`, `VEN-*`, `CUST-*`, `CLI-*`, `PROD-*`), display names, quantities, persisted `unit_price` / `line_total` / `total` / `currency`, notes, status, and `approval_summary`.

Document remains a **search/resolve input**. A seller can type a tax/company document into `search_customers` or `generate_quote_report`. The stored document is not echoed back.

### Fields forbidden in MCP payloads

- Customer `document` values (full tax/company identifiers)
- Customer `email`, `phone`, `contact_name`
- Account passwords and password hashes
- MCP bearer tokens, token hashes, and `Authorization` headers
- Caller-supplied prices (`unit_price`, `line_total`, `total` on generate)

REST customer resources may still return `document`, `email`, and `phone`. That is the operator HTTP door, not the AI vendor.

Default Laravel logs must not persist those withheld fields or bearer tokens. `Authorization` headers fail closed (redacted). Complete MCP request/response bodies are not logged at `debug`/`info` in the default configuration.

### Production checklist (operational, not enforced by Laravel)

- Written DPA or equivalent processing terms with the LLM vendor.
- Disable vendor training and evaluation on customer/quote content when the vendor offers that setting.
- Configure vendor retention to the shortest period the business accepts. Laravel cannot enforce vendor retention.
- Use the authenticated web MCP endpoint against this application. Do not paste production catalog or quote data into a personal LLM account or an unmanaged copy of the dataset.
- HTTPS outside local development (already required for the quote MCP web route).

---

## What each MCP tool is for

| Name | Role |
| --- | --- |
| `search_products` | Find active products by name or code. Cap results (max 10). Prefer exact codes. |
| `search_customers` | Find customers plus their `CLI-*` account. Document is search input only. Never return documents, emails, phones, contact names, or passwords. |
| `generate_quote_report` | Resolve codes, validate, persist one quote, return the report. |
| `get_quote_report` | Load by id or `QUO-*` number. Read stored money, do not reprice. |
| `generate-quote-report` | Prompt only. Teaches the model the workflow above. |

Errors are meant to be specific: not found, ambiguous, inactive, wrong account type, bad quantity, duplicate product, mixed currency, unauthorized seller.

---

## REST surface (the operational door)

These modules also have CRUD (and quote workflow) under `/api`:

- Products: list with search/code/active, create, show, update, delete/inactivate.
- Customers: same idea, plus exact document and email filters.
- Accounts: list by type, search, create with generated `VEN-*` / `CLI-*` codes.
- Quotes: create, show, list, edit while draft, submit, approve, reject.

REST and MCP share the same tables and the same quote action. If you seed mock data, you can exercise either door against the same catalog.

---

## What this system deliberately does not do

No taxes, shipping, inventory, product variants, CRM, PDFs, email delivery, or automatic LLM approval. No OAuth or Sanctum for Quote MCP (usable `mcp_client_tokens` rows for HTTP, configured seller for local stdio). No `user_id`. No JSON metadata columns on these tables.

Those omissions keep the quote report small enough that an agent can create it without inventing commercial rules the business has not specified yet.

---

## A short walk-through

Imagine a seller in Cursor or another MCP client:

1. The client is already configured with `MCP_QUOTE_TOKEN` and talks to `/mcp/quotes`.
2. The seller types `/generate-quote-report` and says they need a quote for Acme, three premium keyboards.
3. The model calls `search_customers` with “Acme”. One active customer with a `CLI-*` account comes back.
4. It calls `search_products` with “premium keyboard”. One active `PROD-*` comes back.
5. It calls `generate_quote_report` with the seller’s `VEN-*` code, that customer, that product, and quantity 3.
6. Laravel hashes the bearer token, attaches `VEN-000001` (or whichever seller is configured), checks the seller is allowed to create that quote, snapshots the current keyboard price, and stores `QUO-2026-000001` as a **draft**.
7. The model returns a report. The seller can later fetch it with `get_quote_report`. Approval still happens through the quote workflow, not by the act of generating the report.

That is the whole solution: a catalog and identities with public codes, a quote engine that snapshots prices, and an authenticated MCP front end that searches in natural language and then writes the same quote a REST client would write.
