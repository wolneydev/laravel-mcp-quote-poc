# MCP Vendor Privacy Controls

## Why

Quote MCP tools return customer and commercial data to the MCP client. That client then includes tool results in the LLM conversation sent to the AI vendor.

The Laravel application currently:

- authenticates the MCP client
- withholds Account passwords
- redacts bearer tokens from Laravel logs
- omits customer `email`, `phone`, and `contact_name` from quote reports

It does **not**:

- redact or minimize tax documents, catalog prices, or quote totals in MCP tool results
- control how long the AI vendor retains those tool results
- encode contractual privacy requirements (DPA, no-training, data residency)

This is a residual privacy risk of the MCP design. The quote report cannot exist without sending some sales context to the model. The gap is sending more than is required, and having no application-level or operational controls around vendor processing.

## What

Reduce the personal and commercial data that Quote MCP tools return to the LLM, document the remaining vendor data flow, and add operational privacy constraints that this application can actually enforce or require.

Keep the existing quote workflow. Do not stop the LLM from seeing the identifiers and line items it needs to create and reread a quote.

## Data currently leaving Laravel toward the LLM

```text
MCP client / LLM vendor
      ^
      | tool results (no PII redaction)
      |
search_customers  -> customer_id, customer_code, customer_name, document, CLI-*
search_products   -> product_id, PROD-*, name, unit, price, currency
generate_quote_report / get_quote_report
                  -> QUO-*, seller name + VEN-*, customer name + CUST-* + CLI-*,
                     product names, quantities, unit prices, line totals, quote total,
                     notes, markdown report
```

Already withheld from MCP payloads:

- Account passwords
- customer `email`
- customer `phone`
- customer `contact_name`

Already withheld from prompts:

- MCP bearer token and token hash

## Constraints

### Must

- Treat MCP tool results as data that will be sent to a third-party AI vendor.
- Minimize MCP customer payloads: do not return full tax/company `document` values in `search_customers` summaries or ambiguous-match candidate lists.
- Keep document as a **search/resolve input** so a seller can still look up a customer by document. Do not echo the stored document back to the model.
- Continue to omit customer `email`, `phone`, and `contact_name` from all MCP tools.
- Continue to never return Account passwords or authentication secrets.
- Keep quote reports limited to public codes, display names, quantities, and persisted money fields required for approval.
- Expand Laravel log redaction so MCP payloads are not logged with raw customer documents, emails, phones, passwords, or bearer tokens.
- Document in `.specs` that:
  - Quote MCP is a third-party LLM data path.
  - Production use requires a vendor DPA (or equivalent), retention limits, and a no-training / no-evaluation setting when the vendor offers it.
  - Local `stdio` and web `/mcp/quotes` have the same payload exposure once the client forwards tool results to the model.
- Fail closed on logging of `Authorization` headers (existing TASK-006 behavior stays).
- Add tests for the minimized MCP customer payload.

### Must Not

- Do not send customer `email`, `phone`, or `contact_name` through MCP “to make the report richer.”
- Do not return full `document` values in MCP search, resolve errors, or quote reports.
- Do not log complete MCP request/response bodies in default logging.
- Do not put vendor API keys, DPAs, or customer PII into prompts.
- Do not treat “the seller already saw this in REST” as permission to dump the same fields into the LLM context.
- Do not disable quote generation. Public codes, customer/product names, quantities, and calculated prices remain required for the report.
- Do not add JSON/`metadata` columns for privacy flags.
- Do not introduce `user_id`.

### Out of Scope

- Signing or storing actual vendor contracts in the application.
- Building a privacy-preference UI.
- GDPR/CCPA subject-request automation.
- Encrypting MCP payloads for the vendor (the vendor must see tool results to use them).
- Changing REST API resources, which remain the operational door for operators.
- Token authentication (already covered by TASK-006).

## Current State

Implemented today:

- `CustomerLookup::summary()` includes `document`.
- `SearchCustomersTool` returns that summary (capped at 10 rows).
- `ProductLookup::summary()` includes catalog `price`.
- `QuoteReportPresenter` returns customer name, seller name, items, prices, and totals, plus a Markdown copy of the same data.
- `RedactSensitiveLogContext` redacts authorization/token keys only.
- TASK-005 required `customers.document` in search output. This task supersedes that requirement for MCP only.

REST customer resources may still return `document`, `email`, and `phone`. That path is operator HTTP, not the AI vendor.

## Tasks

### T1: Minimize customer fields in MCP summaries

What: Remove full `document` from MCP customer payloads.

Suggested files:

- `app/Mcp/Lookups/CustomerLookup.php`
- MCP customer search/report tests

`summary()` (and any ambiguous-match candidate list) must return only:

- `customer_id`
- `customer_code`
- `customer_name`
- `customer_account_id`
- `customer_account_code`
- `active`

Optional allowed addition: `document_present` (boolean) if tests need to show that a document exists without revealing it.

Verify:

- `search_customers` does not contain `document` values.
- ambiguous customer errors do not contain `document` values.
- a customer can still be resolved when the tool input is the document.

### T2: Keep quote reports free of extra PII

What: Confirm `QuoteReportPresenter` never adds `document`, `email`, `phone`, or `contact_name`.

Do not add those fields.

Verify:

- `generate_quote_report` and `get_quote_report` structured output and Markdown omit those fields.

### T3: Keep catalog prices only where the quote engine needs them

What: `search_products` may keep `price` and `currency` because the model must select the right product, and the application still prices from the database. Do not add product `description` to MCP search output.

Verify:

- search product summaries stay limited to identity, unit, price, and currency.
- `generate_quote_report` still rejects caller-provided prices.

### T4: Harden logging against MCP commercial/PII payloads

What: Extend `RedactSensitiveLogContext` (or equivalent) so keys such as `document`, `email`, `phone`, `password`, `authorization`, and token fields cannot appear in logs.

Do not log full MCP tool arguments or structured report bodies at `debug`/`info` in default configuration.

Verify:

- existing token-redaction tests still pass.
- a log record that includes `document` or `email` is redacted.

### T5: Document the vendor data path and operational requirements

What: Add a short privacy section to `.specs/SOLUTION-OVERVIEW.md` (or a dedicated `.specs` note if that matches the existing spec layout) covering:

1. Which MCP fields are allowed to reach the LLM.
2. Which fields are forbidden.
3. That the MCP client/vendor is a processor of quote data.
4. Production checklist:
   - written DPA / processing terms with the LLM vendor
   - disable vendor training on customer content when available
   - configure vendor retention to the shortest period the business accepts
   - use the authenticated web MCP endpoint, not an unmanaged copy of production data in a personal LLM account
   - HTTPS outside local development (already required by TASK-006)

Verify:

- the spec matches the minimized payloads from T1–T2.
- it states clearly that Laravel cannot enforce vendor retention; that is a contractual/client configuration duty.

### T6: Add privacy tests

Cover:

- `search_customers` payload has no `document`, `email`, `phone`, or `contact_name`.
- customer resolve-by-document still works.
- quote report payload has no `document`, `email`, `phone`, or `contact_name`.
- password hashes never appear in MCP responses.
- log redaction covers the new sensitive keys.

## Validation

The task is complete when:

1. MCP customer search/candidates no longer return tax/company document values.
2. MCP quote reports still return public codes, names, quantities, and persisted prices/totals.
3. MCP tools still omit email, phone, contact name, and passwords.
4. A seller can still find a customer by typing a document into `search_customers` / `generate_quote_report`.
5. Laravel default logs do not persist those withheld fields or bearer tokens.
6. Specs describe the remaining vendor exposure and the operational DPA/retention/no-training requirements.
7. Relevant MCP tests pass.
