# MCP Quote Draft PDF Generator

## Why

Sellers using `/generate-quote-report` already get a persisted `draft` quote and a user-visible report with unit prices, line totals, total, and currency (TASK-010). That report is Markdown and structured JSON in the MCP conversation.

They still need a printable PDF of the same draft: something they can save, attach, or print without asking the model to invent a document. TASK-004, TASK-005, and TASK-010 left PDF generation out of scope. TASK-007 also forbids dumping extra PII into the LLM context, so the PDF must be built in Laravel and must not be reconstructed by the model as chat text or base64.

The PDF is a rendering of stored quote snapshots. It is not a second quote, not an approval, and not a pricing engine.

## What

Add a Quote MCP tool **and Laravel PDF generation** for an existing persisted quote (typically a `draft`) from the same fields `QuoteReportPresenter` already returns.

PDF generation is in scope for this task: install a PHP renderer, add a Blade view, produce the binary in Laravel, and expose it through the MCP tool (and optional download route). Do not treat rendering as a follow-up after the tool ships.

Tools (existing, unchanged role):

- `search_products`
- `search_customers`
- `generate_quote_report`
- `get_quote_report`

New tool:

- `generate_quote_draft_pdf`

Prompt / LLM command:

- keep `/generate-quote-report` as the create-and-review entry point
- add `/generate-quote-draft-pdf` as the user-facing command for the PDF
- teach both Prompts that Laravel renders the PDF; the model only requests it

Suggested structure:

- `app/Mcp/Tools/GenerateQuoteDraftPdfTool.php`
- `app/Mcp/Prompts/GenerateQuoteDraftPdfPrompt.php`
- `app/Mcp/Support/QuoteDraftPdfRenderer.php` (or equivalent) that consumes presenter data, not live catalog prices
- a Blade view for the PDF body, for example `resources/views/quotes/draft-pdf.blade.php`

Register the tool in `QuoteServer::$tools` and the Prompt in `QuoteServer::$prompts`.

REST quote CRUD stays in `routes/api.php`. Do not make `/generate-quote-draft-pdf` a duplicate REST resource. A narrow authenticated download route is allowed only if the MCP client cannot save a binary attachment (see Delivery).

## LLM Command: `/generate-quote-draft-pdf`

### Goal

Give an MCP-compatible client a slash command that loads a persisted quote and returns a Laravel-generated PDF of that draft.

The command maps to an MCP Prompt named `generate-quote-draft-pdf`.

### Example user invocation

```text
/generate-quote-draft-pdf
Quote: QUO-2026-000001
```

Or:

```text
/generate-quote-draft-pdf quote_number="QUO-2026-000001"
```

If the seller just created a quote in the same conversation, the model may use the `quote_number` from `generate_quote_report` / `get_quote_report` instead of asking again.

### Required LLM behavior

When `/generate-quote-draft-pdf` is invoked, the prompt must tell the LLM to:

1. Identify the quote by `QUO-*` number or persisted `quote_id`. If neither is known, call `get_quote_report` only after the user supplies an identifier, or reuse the identifier from the last successful `generate_quote_report` in this conversation.
2. Never invent a quote number.
3. Call `generate_quote_draft_pdf` with that identifier.
4. Tell the user the PDF is ready, including quote number, status, total, and currency copied from the tool result.
5. Clearly state that generating a PDF does not approve the quote unless stored status is already `approved`.
6. Never write PDF markup, never calculate money, never paste file bytes or base64 into the chat.

## Data

No new tables.

Reuse:

- `quotes` / `quote_items` snapshots (`unit_price`, `line_total`, `total`, `currency`, product code/name/unit/quantity)
- `QuoteReportPresenter` as the single field set for what appears on the PDF
- `QuotePolicy::view` (same authorization as `get_quote_report`)
- TASK-008 HTTP auth and TASK-009 local stdio seller binding (unchanged)

Do not add JSON/`metadata` columns. Do not add `user_id`. Do not persist a required `pdf_path` column; generate on demand.

## PDF contents

The PDF is the printable form of the TASK-010 draft. It must include:

- quote number (`QUO-*`) and persisted `status`
- seller display name and `VEN-*` code
- customer display name, `CUST-*`, and `CLI-*` (same as the report; no extra customer fields)
- each line: `product_code`, `product_name`, `quantity`, `unit`, `unit_price`, `line_total`
- quote `total` and `currency`
- `valid_until` and `notes` when present
- the same approval wording as `approval_summary` (draft / not approved unless status is `approved`)

Visible status label: if status is not `approved`, the first page must say the quote is a draft or otherwise not approved. Generating the file does not change `quotes.status`.

Fail closed if presenter money fields are missing (reuse `QuoteReportPresenter::present`, which already asserts them).

## Privacy (TASK-007)

The PDF and the MCP tool result must omit:

- customer `document`
- customer `email`
- customer `phone`
- customer `contact_name`
- Account passwords and MCP tokens

Do not put PDF binary content into the model-facing text of the tool result. Structured metadata (quote number, status, total, currency, filename) is enough for the conversation. The file itself is for the MCP client / seller, not for the vendor to parse as tokens.

## Delivery

Prefer this order:

1. Return the PDF as an MCP binary/file attachment the client can save, plus structured metadata (`quote_number`, `status`, `total`, `currency`, `filename`).
2. If the installed `laravel/mcp` version cannot attach a file, store the PDF on the private disk (`storage/app/quotes/drafts/{quote_number}.pdf` or a unique name) and return a short-lived signed URL, or a small authenticated GET under `/api/quotes/{quote}/draft-pdf` that uses `QuotePolicy::view`.

Do not email the PDF (still out of scope).

Do not instruct the model to reconstruct the PDF from Markdown.

## Dependencies

PDF generation is in scope. Use `barryvdh/laravel-dompdf` (DomPDF) inside the existing `app` Docker container. Do not add a headless browser service (Chrome/Puppeteer) for this task.

Generate the document from a Blade view of presenter data. Do not hand-write binary PDF in the tool class.

## Authentication and authorization

Unchanged from `get_quote_report`:

- HTTP `/mcp/quotes`: usable `mcp_client_tokens` row (TASK-008)
- local stdio: `MCP_QUOTE_SELLER_ACCOUNT_CODE` (TASK-009)
- `$request->user()` must be an `Account`
- `Gate::authorize('view', $quote)`

A seller who cannot view the quote must not receive a PDF. Do not generate files for quotes the caller cannot see.

## Constraints

### Must

- Add MCP tool `generate_quote_draft_pdf` on `QuoteServer` (not `ApplicationServer`).
- Input: `quote_id` and/or `quote_number` (`QUO-*`), same required-without pattern as `GetQuoteReportTool`.
- Load the quote with stored items; do not reprice from `products.price`.
- Render only presenter-approved fields; keep TASK-007 omissions.
- Generate the PDF in Laravel (DomPDF + Blade + `QuoteDraftPdfRenderer`) as part of this task, not as a later add-on.
- Include persisted money fields on the PDF; fail if they are missing.
- Leave `quotes.status` unchanged.
- Register Prompt `generate-quote-draft-pdf` and update `GenerateQuoteReportPrompt` so the create-report flow may call the PDF tool after a successful draft **when the user asks for a PDF**.
- Distinguish in the Prompt:
  - **Input:** never pass prices into quote tools; never send PDF bytes.
  - **Output:** money on the PDF comes from Laravel; tell the user the file is ready; do not invent amounts.
- Keep `generate_quote_report` input rejection of `unit_price`, `line_total`, `total` (TASK-005).
- Add feature tests for the tool, Prompt wording, authorization, privacy omissions, money fields, and unchanged status.
- Update `.specs/SOLUTION-OVERVIEW.md` so “no PDFs” is no longer global: this tool is in scope; email delivery remains out.

### Must Not

- Do not let the LLM calculate, discount, or round money for the PDF.
- Do not accept caller-provided prices as PDF input.
- Do not include TASK-007 forbidden customer fields on the PDF or in tool text.
- Do not mark the quote `approved` because a PDF was generated.
- Do not merge this tool into `generate_quote_report` (creating a quote and rendering a PDF stay two calls).
- Do not add JSON/`metadata` columns or `user_id`.
- Do not skip PDF rendering (tool-only / “package later” is not enough).
- Do not log bearer tokens, raw MCP bodies, or PDF binaries in default logs.
- Do not expose the PDF through `laravel-application` / `health_check`.

### In Scope

- Laravel PDF generation of a persisted quote from presenter snapshots (not live catalog prices).
- `barryvdh/laravel-dompdf`, Blade view, and `QuoteDraftPdfRenderer` producing a real PDF binary.
- MCP tool `generate_quote_draft_pdf`, Prompt `generate-quote-draft-pdf`, and optional authenticated `/api/quotes/{quote}/draft-pdf` when MCP cannot attach a file.
- Same money fields and TASK-007 omissions as the conversational draft.

### Out of Scope

- Email or messaging delivery.
- Taxes, shipping, and discounts.
- Automatic approval by an LLM.
- Order creation.
- Branded letterhead, signatures, or multi-language templates beyond one Blade layout.
- Storing PDF history as a domain table.
- Changing REST quote create/update/approve flows except an optional download route for delivery option 2.

## Current State

Implemented today:

- Quote MCP tools: `search_products`, `search_customers`, `generate_quote_report`, `get_quote_report`.
- Prompt `generate-quote-report` requires echoing persisted money on the conversational draft (TASK-010).
- `QuoteReportPresenter` asserts `unit_price`, `line_total`, `total`, and `currency`.
- New MCP quotes start as `draft`.
- PDF is explicitly out of scope in TASK-004, TASK-005, TASK-010, and SOLUTION-OVERVIEW.

Missing:

- any PDF renderer
- MCP tool / Prompt for a printable draft

## Naming

Use these names consistently:

- MCP Prompt name: `generate-quote-draft-pdf`
- user-facing command: `/generate-quote-draft-pdf`
- MCP Tool name: `generate_quote_draft_pdf`

## Tasks

### T1: Add a PDF renderer over presenter data

What: Add `barryvdh/laravel-dompdf` if it is not already required, then add a renderer that turns `QuoteReportPresenter::present($quote)` into a PDF binary.

Use a Blade view. Do not read live `products.price`. Do not add a second field mapper that includes PII omitted from the presenter.

Verify:

- PDF contains quote number, status, seller, customer public codes/names, line money, total, currency.
- PDF does not contain document, email, phone, or contact_name.
- changing `products.price` after create does not change the PDF amounts (same snapshots as `get_quote_report`).

### T2: Add `generate_quote_draft_pdf`

What: Create `GenerateQuoteDraftPdfTool` with `php artisan make:mcp-tool GenerateQuoteDraftPdfTool --no-interaction` (or the project’s MCP generator).

Schema: `quote_id` and/or `quote_number`, matching `GetQuoteReportTool`.

Authorize with `view`. Return structured metadata plus the file (or signed/authenticated download if MCP cannot attach binaries).

Verify:

- unknown quote → not found (same style as `get_quote_report`).
- unauthorized Account → forbidden.
- successful call does not change `status`.
- tools/list on quotes includes the new tool (HTTP and local stdio).
- `ApplicationServer` still only has `health_check`.

### T3: Add Prompt and extend generate-quote-report

What: Create `GenerateQuoteDraftPdfPrompt`. Update `GenerateQuoteReportPrompt` tool list:

- optional last step: if the user wants a PDF, call `generate_quote_draft_pdf` with the persisted `quote_number`
- do not auto-generate a PDF on every `generate_quote_report` unless the user asked for one
- never paste PDF bytes into the reply

Verify:

- Prompt `generate-quote-draft-pdf` is registered.
- Prompt text forbids inventing money and forbids reconstructing the PDF.
- `generate-quote-report` still forbids sending prices as input and still requires showing persisted money on the chat draft.

### T4: Tests

Cover:

- PDF (or renderer unit test) includes persisted money and status label for a `draft`.
- PDF / structured output omits TASK-007 fields.
- tool rejects missing identifier.
- seller who does not own the quote cannot generate the PDF.
- quote remains `draft` after a successful PDF call.
- Prompt asserts Laravel generates the PDF and the model must not calculate prices.
- Quote MCP HTTP and local stdio tool lists include `generate_quote_draft_pdf`.

Suggested:

```bash
php artisan test --compact --filter=GenerateQuoteDraftPdf
php artisan test --compact --filter=QuoteMcpPrompt
php artisan test --compact --filter=QuoteMcpHttp
php artisan test --compact --filter=QuoteMcpLocalStdio
```

### T5: Specs

What: Update `.specs/SOLUTION-OVERVIEW.md`:

- add `generate_quote_draft_pdf` to the MCP tool table
- replace the blanket “No PDFs” line with: quote-draft PDF via this tool is in scope; email delivery is not
- walk-through: after the conversational draft, the seller may request a PDF; Laravel renders it; approval is unchanged

## Validation

The task is complete when:

1. `generate_quote_draft_pdf` is registered on Quote MCP (HTTP and local stdio) and not on `ApplicationServer`.
2. The PDF is rendered in Laravel from persisted quote snapshots, including money fields, and omits TASK-007 PII.
3. `/generate-quote-draft-pdf` instructs the model to call that tool and not to invent prices or PDF content.
4. Generating a PDF does not approve the quote or reprice it.
5. Caller-provided prices remain rejected on `generate_quote_report`.
6. Relevant tests pass.
7. SOLUTION-OVERVIEW no longer lists PDFs as globally forbidden.

## Relation to earlier tasks

| Task | What it did | This task |
| --- | --- | --- |
| TASK-004 | Persist quotes; PDF out of scope | PDF in scope as a rendering of those rows |
| TASK-005 | MCP report tools; PDF out of scope | New tool; create-quote tool unchanged |
| TASK-007 | Minimize PII toward the LLM | PDF uses the same omissions; no binary in chat |
| TASK-008 / TASK-009 | HTTP tokens and local stdio | Unchanged; new tool rides the same server |
| TASK-010 | User-visible draft must show persisted money | PDF must show the same persisted money |
| TASK-011 (this) | Printable draft PDF generated by Laravel (renderer + MCP tool) | New |
