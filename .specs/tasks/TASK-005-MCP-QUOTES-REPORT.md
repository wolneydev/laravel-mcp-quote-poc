# MCP Quote Report Module

## Why

A seller must be able to provide a customer, product name/code, and quantity through MCP and receive a persisted quote report suitable for review and approval.

MCP is the consultation/report-generation interface. It must reuse the same quote domain logic as the REST API instead of implementing quote calculation again.

## What

Create a Laravel MCP server registered in `routes/ai.php` with four tools and one reusable MCP prompt:

Tools:

- `search_products`
- `search_customers`
- `generate_quote_report`
- `get_quote_report`

Prompt / LLM command:

- `/generate-quote-report`

The slash command is the user-facing entry point for an LLM client. It must guide the model to collect the required quote information and then invoke the `generate_quote_report` MCP tool. The slash command itself must not duplicate business or pricing logic.

Suggested structure:

- `app/Mcp/Servers/QuoteServer.php`
- `app/Mcp/Tools/SearchProductsTool.php`
- `app/Mcp/Tools/SearchCustomersTool.php`
- `app/Mcp/Tools/GenerateQuoteReportTool.php`
- `app/Mcp/Tools/GetQuoteReportTool.php`
- `app/Mcp/Prompts/GenerateQuoteReportPrompt.php`
- `routes/ai.php`

REST CRUD endpoints remain in `routes/api.php`.

MCP server registration belongs in `routes/ai.php`, not `routes/api.php`.

Use the authentication middleware/guard already configured for the `Account` model.

## LLM Command: `/generate-quote-report`

### Goal

Provide a simple, explicit command that an MCP-compatible LLM client can expose to the user for starting quote report generation.

The command maps to an MCP Prompt named `generate-quote-report`. MCP Prompts are reusable prompt templates exposed by the server; clients that support slash-command presentation may surface this prompt as `/generate-quote-report`.

The prompt must instruct the LLM to gather or resolve:

- seller Account code (`VEN-*`), unless it can be safely inferred from the authenticated Account.
- Customer name, Customer code (`CUST-*`), or customer Account code (`CLI-*`).
- Product name or Product code (`PROD-*`).
- quantity for each Product.
- optional `valid_until`.
- optional `notes`.

### Example user invocation

```text
/generate-quote-report
Customer: Acme Ltd
Product: Premium Keyboard
Quantity: 3
```

Or, when the client supports arguments on the same command:

```text
/generate-quote-report customer="Acme Ltd" product="Premium Keyboard" quantity=3
```

### Required LLM behavior

When `/generate-quote-report` is invoked, the prompt must tell the LLM to:

1. Identify the authenticated seller Account or request a valid `VEN-*` code when it cannot be inferred.
2. Resolve the Customer using `search_customers` when the supplied value is not an exact unambiguous public code.
3. Resolve each Product using `search_products` when the supplied value is not an exact unambiguous public code.
4. Never guess when Customer or Product lookup is ambiguous.
5. Ask only for missing required information that cannot be resolved through available MCP tools.
6. Call `generate_quote_report` only after seller, Customer, Products, and quantities are unambiguous and valid.
7. Return the generated persisted quote report to the user.
8. Clearly state that the generated quote is not approved unless its persisted status is actually `approved`.

### Prompt implementation

Create a Laravel MCP Prompt named `generate-quote-report`.

Suggested file:

- `app/Mcp/Prompts/GenerateQuoteReportPrompt.php`

Generate it using the Laravel MCP prompt generator when available:

```bash
php artisan make:mcp-prompt GenerateQuoteReportPrompt
```

Register the Prompt in `QuoteServer::$prompts`. The Prompt response should provide instructions to the connected LLM; it must not create a Quote directly. Quote creation remains exclusively in `GenerateQuoteReportTool` through `CreateQuoteAction`.

The prompt should support optional initial arguments when the MCP client provides them:

- `customer`
- `product`
- `quantity`
- `seller_account_code`
- `valid_until`
- `notes`

Missing arguments are acceptable because the LLM may obtain them conversationally before calling the tool.

### Naming

Use these names consistently:

- MCP Prompt name: `generate-quote-report`
- user-facing command: `/generate-quote-report`
- MCP Tool name: `generate_quote_report`

Do not use the slash-prefixed name as the actual MCP Tool name. The slash command is a client-facing representation of the MCP Prompt, while `generate_quote_report` remains the executable MCP Tool.

## Tool: `search_products`

### Input

- `query` — required string containing a Product name or code.
- `active_only` — optional boolean, default `true`.
- `limit` — optional integer, maximum 10.

### Behavior

Search:

- exact or partial `products.code`.
- partial `products.name`.

Prefer exact code matches.

### Output

Return only:

- `id`
- `code`
- `name`
- `unit`
- `price`
- `currency`
- `active`

## Tool: `search_customers`

### Input

- `query` — required string containing Customer name, Customer code, document, or customer Account code.
- `active_only` — optional boolean, default `true`.
- `limit` — optional integer, maximum 10.

### Behavior

Search Customers by:

- `customers.code`
- `customers.name`
- `customers.document`

Also support exact lookup through the related `CLI-*` Account code.

A Customer is quote-ready only when:

- the Customer is active.
- the related customer Account exists.
- the related Account is active and has `type=customer`.

### Output

Return:

- `customer_id`
- `customer_code`
- `customer_name`
- `document` when appropriate.
- `customer_account_id`
- `customer_account_code`
- `active`

Do not return Account authentication secrets.

## Tool: `generate_quote_report`

### Goal

Allow the seller to provide:

- seller Account code.
- Customer by name/code/customer Account code.
- Product by name/code.
- quantity.

The tool resolves public identifiers to database IDs, calls the same `CreateQuoteAction` used by the REST API, persists the quote, and returns the report.

### Input

Minimum structured input:

```json
{
  "seller_account_code": "VEN-000001",
  "customer": "CUST-000001",
  "items": [
    {
      "product": "PROD-000001",
      "quantity": 3
    }
  ],
  "valid_until": "2026-09-30",
  "notes": "Optional"
}
```

`customer` may be:

- exact Customer code.
- exact customer Account code.
- Customer name only when lookup returns exactly one valid Customer.

Each `product` may be:

- exact Product code.
- Product name only when lookup returns exactly one active Product.

### Resolution flow

1. Resolve `seller_account_code`.
2. Require an active Account with `type=seller`.
3. If MCP is authenticated as an Account, require the authenticated Account to match the requested seller Account unless an explicit administrative policy allows another seller.
4. Resolve the Customer.
5. Resolve the Customer's related Account.
6. Require the customer Account to be active and `type=customer`.
7. Require the Customer to be active.
8. Resolve every Product.
9. Require every Product to be active.
10. Reject ambiguous Customer or Product names.
11. Validate every quantity as greater than zero.
12. Reject repeated Products in one request.
13. Reject Products with different currencies in the same quote.
14. Call the existing `CreateQuoteAction`.
15. Persist the Quote and QuoteItems in the action's transaction.
16. Build the response from the persisted Quote.

### Output

Return at least:

- `quote_id`
- `quote_number`
- `status`
- `created_at`
- `valid_until`
- `seller`
  - `account_id`
  - `account_code`
  - `name`
- `customer`
  - `customer_id`
  - `customer_code`
  - `customer_name`
  - `account_id`
  - `account_code`
- `items`
  - `product_id`
  - `product_code`
  - `product_name`
  - `unit`
  - `quantity`
  - `unit_price`
  - `line_total`
- `total`
- `currency`
- `notes`
- `approval_summary`

`approval_summary` is a short human-readable summary of the persisted quote. It must not change quote status.

A Markdown representation may also be returned if useful to the MCP client, but the structured data must remain the source of truth.

## Tool: `get_quote_report`

### Input

Accept one of:

- `quote_id`
- `quote_number`

At least one is required.

### Behavior

Load:

- Quote.
- seller Account.
- customer Account.
- related Customer.
- QuoteItems.

Use persisted `quote_items.unit_price`, `quote_items.line_total`, and `quotes.total`.

Do not recalculate historical quote values from the current Product price.

### Output

Return the same report structure used by `generate_quote_report`.

## Constraints

### Must

- Register MCP in `routes/ai.php`.
- Register the `generate-quote-report` MCP Prompt in `QuoteServer`.
- Keep `/generate-quote-report` as the documented user-facing command name for MCP clients that expose prompts as slash commands.
- Keep `generate_quote_report` as the executable MCP Tool name.
- Keep REST CRUD routes in `routes/api.php`.
- Do not create duplicate `/api/mcp/*` endpoints.
- Use the Laravel MCP package/version compatible with the existing Laravel application.
- Use `Account` as the authenticated identity model.
- Protect MCP using the application's existing authentication and authorization approach.
- Limit search results to a small maximum.
- Prefer public codes for deterministic resolution:
  - `VEN-*`
  - `CLI-*`
  - `CUST-*`
  - `PROD-*`
  - `QUO-*`
- A name-based Customer/Product resolution must produce exactly one result before quote creation.
- If lookup is ambiguous, return a short candidate list and do not create a Quote.
- Validate the complete request before committing the Quote.
- Call the existing `CreateQuoteAction`.
- Use the existing `QuotePricingService`.
- MCP tools must not contain a second quote calculation implementation.
- Product price and currency must come from persisted Products.
- Seller/customer Account type checks must occur before quote creation.
- Tool input schemas must document required fields, limits, and numeric constraints.
- Errors must clearly distinguish:
  - not found.
  - ambiguous match.
  - inactive Product.
  - inactive Customer.
  - inactive Account.
  - wrong Account type.
  - invalid quantity.
  - repeated Product.
  - mixed currency.
  - unauthorized seller.
- Never expose Account passwords or authentication secrets.
- Add MCP integration tests.

### Must Not

- Do not add JSON or `metadata` database fields.
- Do not introduce `users` or `user_id`.
- Do not duplicate quote creation/calculation logic.
- Do not implement quote creation inside `GenerateQuoteReportPrompt`; the Prompt only instructs the LLM how to use the tools.
- Do not make `/generate-quote-report` a duplicate REST endpoint.
- Do not accept caller-provided `unit_price`, `line_total`, or `total`.
- Do not recalculate persisted quote values when retrieving a report.
- Do not mark a Quote as `approved` merely because the report was generated.
- Do not return unlimited Product or Customer results.

### Out of Scope

- PDF report generation.
- Email or messaging delivery.
- Automatic approval by an LLM.
- Order creation.
- Taxes and shipping.

## Current State

These modules are already implemented:

- Products.
- Customers.
- Accounts.
- Quotes.

Important domain rules already available:

- `accounts` is the authentication/user table.
- seller Accounts use `VEN-*`.
- customer Accounts use `CLI-*` and reference `customers.id`.
- Quotes reference seller/customer Account IDs directly.
- Quote item prices are persisted in scalar columns.
- `CreateQuoteAction` and `QuotePricingService` are the single source of quote creation and calculation logic.

## Tasks

### T1: Ensure Laravel MCP support is available

What: Confirm that the Laravel MCP package compatible with the application is installed and `routes/ai.php` is available.

Files may include:

- `composer.json`
- `composer.lock`
- `routes/ai.php`

Verify:

- MCP package commands/classes are available.
- the application boots normally.

### T2: Create QuoteServer

What: Create the MCP server and register the four tools plus the `generate-quote-report` Prompt.

Files:

- `app/Mcp/Servers/QuoteServer.php`

Verify:

- the server exposes only the intended quote tools.
- the server exposes the `generate-quote-report` Prompt.

### T3: Implement GenerateQuoteReportPrompt

What: Create the reusable MCP Prompt that clients may expose as `/generate-quote-report`.

Files:

- `app/Mcp/Prompts/GenerateQuoteReportPrompt.php`
- `app/Mcp/Servers/QuoteServer.php`
- related tests.

The Prompt must guide the LLM to resolve missing Customer/Product identifiers, gather quantities, and call `generate_quote_report` only when the request is unambiguous.

Verify:

- the Prompt is registered as `generate-quote-report`.
- it can accept optional initial arguments.
- its instructions reference `search_products`, `search_customers`, and `generate_quote_report`.
- the Prompt itself does not persist Quotes or calculate prices.

### T4: Register the server in routes/ai.php

What: Register the Quote MCP server using the package convention and existing authentication/throttling middleware.

Files:

- `routes/ai.php`

Verify:

- MCP registration is available.
- no duplicate MCP route exists in `routes/api.php`.
- `/generate-quote-report` is documented as a client-facing Prompt command, not as an HTTP route.

### T5: Implement SearchProductsTool

What: Implement limited Product lookup.

Files:

- `app/Mcp/Tools/SearchProductsTool.php`
- related tests.

Verify:

- exact code lookup works.
- partial name lookup works.
- inactive Products are excluded by default.
- the limit is enforced.

### T6: Implement SearchCustomersTool

What: Implement Customer lookup together with the related customer Account.

Files:

- `app/Mcp/Tools/SearchCustomersTool.php`
- related tests.

Verify:

- exact Customer code works.
- exact `CLI-*` Account code works.
- partial Customer name works.
- ambiguous names return candidates rather than selecting one.
- authentication secrets are never returned.

### T7: Implement GenerateQuoteReportTool

What: Define the input schema, resolve identifiers, validate Account roles/active states, call `CreateQuoteAction`, and return the persisted report.

Files:

- `app/Mcp/Tools/GenerateQuoteReportTool.php`
- optional reusable `QuoteReportPresenter`.
- related tests.

Verify:

- valid `VEN-* + Customer + Product + quantity` creates exactly one Quote.
- the Quote receives the correct `seller_account_id` and `customer_account_id`.
- Product price is copied into QuoteItem.
- report total matches the persisted Quote total.

### T8: Implement GetQuoteReportTool

What: Load a Quote by ID or number and format it through the same report presenter.

Files:

- `app/Mcp/Tools/GetQuoteReportTool.php`
- related tests.

Verify:

- changing `products.price` after quote creation does not change the retrieved report amount.

### T9: Add authorization and query protections

What: Apply authentication, seller ownership checks, customer read rules where applicable, result limits, and throttling consistent with the application.

Verify:

- a seller cannot create a Quote under another seller Account unless explicitly authorized.
- unauthorized Quote reads are rejected.
- search tools cannot dump entire tables.

### T10: Add MCP integration tests

Cover:

- `generate-quote-report` Prompt registration and response.
- Prompt arguments and missing-argument behavior.
- tool schemas.
- exact lookup.
- partial lookup.
- ambiguous lookup.
- inactive entities.
- Account type validation.
- authenticated seller ownership.
- quote creation.
- shared pricing service.
- persisted historical price.
- report retrieval.
- invalid quantity.
- repeated Product.
- mixed currency.

Verify:

```bash
php artisan test --filter=Mcp
php artisan test --filter=Quote
```

pass.

## Validation

The MCP flow is complete when:

1. The server exposes the `generate-quote-report` MCP Prompt, which compatible clients may present as `/generate-quote-report`.
2. Invoking `/generate-quote-report` guides the LLM to collect or resolve Customer, Product, quantity, and seller context before quote generation.
3. `search_products` finds a Product by partial name and returns its public code.
4. `search_customers` finds a Customer and its `CLI-*` Account.
5. `generate_quote_report` accepts a valid `VEN-*` seller, Customer, Product, and quantity.
6. Exactly one Quote and its QuoteItems are persisted.
7. `seller_account_id` points to an Account with `type=seller`.
8. `customer_account_id` points to an Account with `type=customer` linked to the resolved Customer.
9. Quote values are calculated only by the shared quote domain service.
10. The report contains persisted item prices and totals.
11. Changing Product price later does not change `get_quote_report`.
12. Ambiguous Product or Customer names never create a Quote.
13. The Prompt never duplicates persistence or pricing logic.
14. No duplicate MCP REST endpoint exists.
15. The Laravel test suite passes.
