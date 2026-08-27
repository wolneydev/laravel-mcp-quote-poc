# Return Persisted Prices and Totals on MCP Quote Drafts

## Why

Sellers using `/generate-quote-report` need to see unit prices, line totals, currency, and the quote total on the draft that comes back from MCP. That is the review document. A quote without money is not usable for approval.

Earlier tasks split pricing in two directions and the split is easy to misread:

- **TASK-004** and **TASK-005** correctly forbade the caller (REST or LLM) from **sending** `unit_price`, `line_total`, or `total` as input. Laravel prices the quote from `products.price` through `QuotePricingService`.
- **TASK-005** also told the `generate-quote-report` Prompt: do not calculate prices, and never send `unit_price`, `line_total`, or `total`. That instruction is about **tool arguments**, not about hiding money from the user.
- **TASK-007** listed catalog prices and quote totals as residual vendor-privacy risk, then **did not** remove them from reports. Validation item 2 still required MCP quote reports to return persisted prices and totals.

The application already persists and returns those fields (`QuoteReportPresenter`, `generate_quote_report`, `get_quote_report`). The Prompt and tests still emphasize “Never send `unit_price`” without an equally strong “always show the persisted money fields to the user.” Connected models then omit totals from the conversational draft, or treat “do not calculate prices” as “do not display prices.”

This task does not undo TASK-005 input rejection. It makes the **output and the user-visible draft** explicit: after Laravel creates a `draft` quote, the seller must see the stored prices and total.

## What

Require Quote MCP to return persisted money fields on every generated or retrieved quote report, and require the `generate-quote-report` Prompt to tell the model to copy those fields into the user-facing draft.

Keep:

- caller-provided `unit_price`, `line_total`, and `total` **prohibited** on `generate_quote_report` input (TASK-005).
- privacy omissions from TASK-007 (`document`, `email`, `phone`, `contact_name`).
- historical amounts from `quote_items` / `quotes`, never live catalog recalculation on `get_quote_report`.

Do not add a second pricing engine. Do not let the LLM invent or round money.

## Current State

Implemented today:

- `GenerateQuoteReportTool` rejects `items.*.unit_price`, `items.*.line_total`, `items.*.total`, and top-level `total`.
- `CreateQuoteAction` + `QuotePricingService` snapshot `unit_price`, `line_total`, and `quotes.total`.
- New MCP quotes start as `draft`.
- `QuoteReportPresenter` already includes `items.*.unit_price`, `items.*.line_total`, `total`, `currency`, and Markdown lines for unit price, line total, and total.
- Feature tests assert those fields on tool structured output.
- `GenerateQuoteReportPrompt` says:
  - “Do not calculate prices, totals, or discounts.”
  - “Never send `unit_price`, `line_total`, or `total`.”
  - Step 7: return quote number, status, items, total, and currency (no explicit `unit_price` / `line_total`).
- `QuoteMcpPromptTest` asserts `Never send \`unit_price\`` and does not assert that the Prompt requires echoing persisted money fields.

## Constraints

### Must

- Keep `generate_quote_report` input validation: `unit_price`, `line_total`, and `total` remain `prohibited`.
- Keep structured report output (and Markdown) with at least:
  - each item: `product_code`, `product_name`, `quantity`, `unit`, `unit_price`, `line_total`
  - quote: `total`, `currency`, `quote_number`, `status`
- After a successful `generate_quote_report` or `get_quote_report`, the Prompt must instruct the model to show those persisted money fields to the user. Copy from the tool result. Do not recompute.
- Distinguish input vs output in the Prompt in plain language:
  - **Input:** never pass prices or totals into `generate_quote_report`.
  - **Output:** always present the server-returned `unit_price`, `line_total`, `total`, and `currency` on the draft.
- Keep TASK-007 forbidden fields out of reports.
- Add tests for Prompt wording and for report money fields on a `draft` quote.
- Fail closed if a report is missing money fields; do not ship a quote draft that only lists names and quantities.

### Must Not

- Do not accept caller-provided prices as a source of truth (TASK-004 / TASK-005).
- Do not tell the model to calculate, discount, or round money itself.
- Do not strip `unit_price`, `line_total`, or `total` from `QuoteReportPresenter` or MCP tool results.
- Do not treat TASK-007 privacy minimization as permission to hide commercial amounts from the seller-facing draft.
- Do not add JSON/`metadata` columns.
- Do not introduce `user_id`.
- Do not mark the quote `approved` because a report with prices was returned.

### Out of Scope

- Taxes, shipping, and discounts.
- Searching customers by `contact_name` over MCP (still omitted from payloads by TASK-007; REST search may still use it).
- Changing REST quote resources (they already return money).
- PDF or email delivery.

## Data currently leaving Laravel toward the LLM (money)

Unchanged from TASK-007, restated so this task cannot be read as a redact-prices task:

```text
search_products            -> catalog price, currency (selection only)
generate_quote_report /
get_quote_report           -> unit_price, line_total, total, currency
                               (persisted snapshots on the draft/report)
```

Forbidden on **input** to `generate_quote_report`:

```text
unit_price, line_total, total
```

## Tasks

### T1: Make the Prompt echo persisted money on the draft

What: Update `app/Mcp/Prompts/GenerateQuoteReportPrompt.php` so the model cannot confuse “do not send prices” with “do not show prices.”

Required Prompt behavior:

1. Keep: do not create a quote yourself; do not calculate prices, totals, or discounts.
2. Keep: never send `unit_price`, `line_total`, or `total` as `generate_quote_report` arguments.
3. Add: after `generate_quote_report` succeeds, show the persisted draft including:
   - quote number and status (`draft` unless the stored status is otherwise)
   - each line: product code/name, quantity, unit, `unit_price`, `line_total`
   - quote `total` and `currency`
4. Say explicitly that those numbers come only from the tool result.
5. If the tool result is missing money fields, say so and do not invent replacements.
6. Keep the not-approved warning unless status is actually `approved`.

Verify:

- Prompt text contains both the input prohibition and the output requirement.
- Prompt still does not mention bearer tokens or `CreateQuoteAction`.

### T2: Keep presenter money fields required on drafts

What: Confirm `QuoteReportPresenter` (used by `generate_quote_report` and `get_quote_report`) still returns money fields when `status` is `draft`.

Do not remove Markdown total / unit price / line total rows.

Verify:

- structured output includes `total`, `currency`, `items.*.unit_price`, `items.*.line_total`.
- Markdown includes the same amounts.
- a newly generated quote is `draft` and still includes those fields.

### T3: Keep input rejection

What: Do not regress TASK-005.

`GenerateQuoteReportTool` must still reject caller-provided prices.

Verify:

- request with `items.*.unit_price` or top-level `total` fails validation.
- successful create still prices from `QuotePricingService`.

### T4: Tests

Cover:

- Prompt asserts the user-visible draft must include `unit_price`, `line_total`, `total`, and `currency` from the tool result.
- Prompt still asserts never sending those fields as tool input.
- `generate_quote_report` for a draft returns money fields.
- `get_quote_report` for a draft returns the same persisted amounts.
- changing `products.price` after create does not change the retrieved report (existing TASK-005 behavior).

Suggested:

```bash
php artisan test --compact --filter=QuoteMcpPrompt
php artisan test --compact --filter=GenerateQuoteReport
php artisan test --compact --filter=GetQuoteReport
```

## Validation

The task is complete when:

1. Specs and Prompt state that TASK-005 removed prices from **input**, not from the **draft the seller sees**.
2. `/generate-quote-report` / `generate_quote_report` success path returns a draft that includes unit prices, line totals, total, and currency.
3. The Prompt requires the model to copy those persisted values to the user and forbids inventing them.
4. Caller-provided prices are still rejected.
5. TASK-007 PII omissions remain.
6. Relevant MCP prompt and quote report tests pass.

## Relation to earlier tasks

| Task | What it did with money | This task |
| --- | --- | --- |
| TASK-004 | Persist snapshots; never trust caller prices | Unchanged |
| TASK-005 | Tool input `prohibited`; Prompt “never send” prices; report **output** already listed `unit_price` / `line_total` / `total` | Clarify Prompt so output is shown |
| TASK-007 | Considered minimizing catalog prices / totals; **kept** them on reports | Do not interpret privacy as stripping draft money |
| TASK-010 (this) | User-visible MCP draft must return persisted prices and totals | New |
