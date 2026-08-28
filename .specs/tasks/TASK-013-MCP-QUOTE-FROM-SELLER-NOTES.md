# MCP Quote From Seller Notes File

## Why

Sellers often capture a visit or call as a **notes file** (typed annotations, a meeting dump, a `.txt` / `.md` attachment). Today Quote MCP only starts from **chat text**: `/generate-quote-report` expects the model to gather customer, products, and quantities conversationally (TASK-005), then persist via `generate_quote_report`, show money (TASK-010), and optionally save a PDF (TASK-011 / TASK-012).

There is **no** task that:

- reads a seller notes file inside Laravel,
- turns that file into a structured briefing,
- feeds that briefing into the existing Quote MCP prompt/tool chain.

If the seller “just sent a note,” the LLM client must paste or retype it. Laravel never extracts candidates, never redacts prices/PII from the file, never has a dedicated prompt for that path, and the **initial page (`GET /`)** has no upload. This task adds that ingress. It does **not** add a second quote engine.

## What

Add a Quote MCP **ingest tool** and a **prompt** so a notes file becomes input to the **existing** create flow.

The Laravel app:

1. Lets the seller **upload the notes file on the PoC landing** (`GET /`, `resources/views/welcome.blade.php` — not the default Laravel starter) and stores it on the private disk.
2. **On that same POST**, Laravel runs the notes ingest briefing and then the **same quote-create path as MCP** `generate_quote_report` (`CreateQuoteAction` + `QuotePricingService`). Catalog prices are snapshotted. Note prices are ignored.
3. Redirects back to `GET /` with the **persisted draft** (quote number, status, line money, total, currency, not-approved wording). Ambiguous or empty briefings fail closed: file may be stored, **no** quote is written, errors show on the page.
4. The same page shows the draft **and an Approve control**. Approving is a second POST, never automatic on upload. Laravel submits then approves through the **existing quote workflow actions** (`SubmitQuoteAction`, `ApproveQuoteAction`) — the same domain as REST `/api/quotes/{quote}/submit` and `/approve`. There is **no** Quote MCP approve tool; do not invent one, do not JSON-RPC `/mcp/quotes`, and do not use `generate_quote_report` to flip status.
5. Still exposes Quote MCP ingest + generate for LLM clients that paste text or a `storage_path`.
6. On `GET /`, **two columns** (desktop): **left** is the notes path (step-by-step, `.txt` template, upload). **Right** is the LLM/MCP path (Markdown: Claude Code, Codex, slash prompts, tools, future in-app LLM API). That future path is **documentation only** — do not call an LLM vendor from Laravel here.

The LLM client path still does not price, approve, or invent `QUO-*` numbers. The home-page POST does not HTTP-proxy JSON-RPC to `/mcp/quotes` (no bearer token in the browser). It calls the **same application services** the MCP generate tool uses.

Suggested structure:

- `resources/views/welcome.blade.php` — **replace** the default Laravel starter. `GET /` is the PoC landing for notes → private store → MCP quote, not docs/Laracasts/deploy marketing.
- web POST route for that form (CSRF), e.g. `POST /seller-notes`
- `app/Http/Controllers/...` — store UTF-8 notes, then create the draft via the shared quote engine
- `app/Actions/Quotes/CreateQuoteFromSellerNotesAction.php` — ingest briefing → lookup → `CreateQuoteAction`
- `app/Mcp/Support/SellerQuoteNotesIngestor.php` — parse text → briefing
- `app/Mcp/Tools/IngestSellerQuoteNotesTool.php` — MCP tool
- `POST /seller-notes/quotes/{quote}/approve` (named `seller-notes.quotes.approve`) — CSRF; seller confirms after the draft is on screen
- reuse `SubmitQuoteAction` + `ApproveQuoteAction` (REST workflow). Quote MCP has no approve tool; do not add one in this task.
- `.cursor/commands/generate-quote-from-notes.md` — client-facing command, same wording as the Prompt
- `resources/seller-notes/template.txt` — instructional notes template on `GET /`
- `GET /seller-notes/template.txt` (`seller-notes.template`) — download that file (not the public disk)
- `resources/seller-notes/mcp-connect.md` — Claude/Codex connect guide, slash/tool catalog, future LLM API (rendered as Markdown in the **right** column on `GET /`)

Register the tool in `QuoteServer::$tools` and the Prompt in `QuoteServer::$prompts`.

Do not merge ingest into the MCP tool `generate_quote_report`. The MCP **tool** `ingest_seller_quote_notes` still never writes a quote. The **home-page POST** may persist a draft by calling the shared create action after ingest + unambiguous lookup. Quote create and PDF save remain separate (TASK-012); the landing does not auto-write a PDF.

## Current state (gap)

| Layer | What exists | What is missing |
| --- | --- | --- |
| TASK-005 | Prompt + tools for **typed** customer/product/qty | File / annotations ingress |
| TASK-004 | Optional `quotes.notes` on persist | Reading a **source** notes file |
| TASK-007 | Minimize PII in tool **results** | Redact PII/prices **from inbound notes** before they hit the vendor |
| TASK-011/012 | Outbound PDF of a stored quote | Inbound document → briefing |
| `GET /` welcome | Default Laravel starter (or a hidden upload tacked on it) | **PoC landing** for notes → quote; obvious post-upload next step |

`quotes.notes` is leftover commercial text on the **created** quote. It is not an ingest pipeline. The home page does not accept seller annotations today.

## LLM Command: `/generate-quote-from-notes`

### Goal

Give an MCP-compatible client a slash command: “here is the seller’s notes file; resolve catalog entities and generate a draft quote the same way `/generate-quote-report` does.”

The command maps to MCP Prompt `generate-quote-from-notes`.

### Example user invocation

```text
/generate-quote-from-notes
(attach or paste notes.txt)
```

Or, after a private-disk upload:

```text
/generate-quote-from-notes storage_path="quotes/notes/{id}.txt"
```

Example notes body (seller annotations, messy on purpose):

```text
Visit 28/08 — Acme Ltd
3x premium keyboard, 1 mouse
They mentioned R$ 199 but I said catalog price
Valid until end of month
Ship to the usual address — João 11 99999-0000
```

Laravel ingest must **not** use `R$ 199` as `unit_price`. It must **not** echo João’s phone into the tool result. The briefing should surface customer “Acme Ltd”, products “premium keyboard” / “mouse”, quantities 3 and 1, optional valid-until hint, and sanitized leftover notes.

### Required LLM behavior

When `/generate-quote-from-notes` is invoked, the prompt must tell the LLM to:

1. If the user attached text or a `storage_path`, call `ingest_seller_quote_notes` first. Do not invent a briefing from a filename alone.
2. Treat the ingest result as **candidates**, not as resolved public codes and not as prices.
3. Identify the authenticated seller (`VEN-*`) the same way as `/generate-quote-report`.
4. For each customer mention, call `search_customers` until exactly one quote-ready match, or ask the seller. Never guess on ambiguous results.
5. For each product mention, call `search_products` the same way. Pair quantities from the briefing; if quantity is missing or ambiguous, ask.
6. Ignore any `unit_price` / totals / currency amounts that appeared in the original notes. Never pass them to `generate_quote_report`.
7. Call `generate_quote_report` only after seller, customer, products, and quantities are unambiguous. Pass codes, quantities, optional `valid_until`, optional sanitized `notes` from the briefing — never prices.
8. After persist, follow TASK-010 / TASK-012: show persisted money from the tool result, state that the quote is not approved, **ask** whether to save a PDF, call `generate_quote_draft_pdf` only on consent.
9. If ingest returns no usable customer or product mentions, ask the seller; do not call `generate_quote_report` on an empty briefing.
10. Never paste the raw notes file, file bytes, or base64 into later tool arguments when a briefing already exists.

## Tool: `ingest_seller_quote_notes`

### Input (one of)

- `text` — UTF-8 notes body (required if no `storage_path`).
- `storage_path` — object key on the `local` disk under `quotes/notes/` (required if no `text`).
- `filename` — optional original name for logs/metadata only (no path traversal).

Reject if both missing. If both present, `text` wins and `storage_path` is ignored (or reject as ambiguous — pick one and test it).

Max size: fail closed above a documented limit (suggested 64 KiB of decoded text). Do not ingest binaries as “text.”

### Behavior

1. Require authenticated seller (`RequiresSellerAccount`), same as other quote tools.
2. Load text from the argument or from `Storage::disk('local')->get($storage_path)`. Path must stay under `quotes/notes/` on the `local` disk. Reject `..`, absolute paths, and other disks.
3. Normalize newlines; reject empty body after trim.
4. Run `SellerQuoteNotesIngestor`:
   - Extract customer-like phrases (named companies/people lines, “customer:”, “client:”).
   - Extract product-like phrases and quantities (`3x`, `qty 2`, “3 premium keyboard”).
   - Detect money-like tokens (`R$`, `$`, `USD`, `unit_price`, totals) and record `prices_ignored: true` without returning those amounts.
   - Redact inbound PII patterns before the briefing is returned: emails, phone-like numbers, and long digit runs that look like tax documents. Do not echo them in the tool result (TASK-007). They may remain in the private file on disk.
5. Return structured JSON only. Do not persist a quote.

### Output (structured)

```json
{
  "filename": "visit-acme.txt",
  "storage_path": null,
  "customer_mentions": ["Acme Ltd"],
  "line_candidates": [
    { "product_mention": "premium keyboard", "quantity": 3 },
    { "product_mention": "mouse", "quantity": 1 }
  ],
  "valid_until_hint": "end of month",
  "notes_remainder": "Visit 28/08. Ship to the usual address.",
  "prices_ignored": true,
  "pii_redacted": true,
  "warnings": []
}
```

`valid_until_hint` is a **hint for the model to ask or parse**, not a guaranteed ISO date. Laravel must not invent `PROD-*` / `CUST-*` here. Resolution is search tools.

Do not include: raw file bytes, original unsanitized body, emails, phones, documents, prices, tokens.

### Errors

- 401 / unauthorized seller: same as other quote tools.
- Missing text and path; empty body; oversize; path outside `quotes/notes/`; unreadable file: fail closed with a specific error, no briefing.

## Required: `GET /` is the notes-to-quote PoC page

The seller must be able to send the notes file from the **project home page**, not only by pasting into an MCP client.

**Page:** `GET /` → `resources/views/welcome.blade.php` (route in `routes/web.php`). This is the Laravel initial page of the PoC.

Replace the **entire** default Laravel welcome (Let’s get started, Documentation, Laracasts, Deploy now, Laravel logo lockup). The whole page must project this idea:

1. A seller captures a visit as a notes file.
2. The seller uploads it on `GET /`. Laravel stores it privately (`quotes/notes/` on the `local` disk).
3. **In the same request**, Laravel ingests a briefing, resolves catalog entities (fail closed if ambiguous or missing), and persists a **draft** through `CreateQuoteAction` / `QuotePricingService` — the same engine as MCP `generate_quote_report`. Do not send note prices. Do not call `/mcp/quotes` JSON-RPC from the browser (tokens stay off the page).
4. The same page shows the draft: `QUO-*`, status `draft`, persisted money, and that it is **not approved**, plus an **Approve** button.
5. If the seller clicks Approve, a second CSRF POST runs the existing workflow: `draft` → `pending_approval` (`SubmitQuoteAction`, authorized as the owning seller) → `approved` (`ApproveQuoteAction`). The PoC landing does this in one confirmed click because there is no customer browser session. REST `POST /api/quotes/{quote}/approve` stays **customer-only**. MCP `generate_quote_report` / `ingest_seller_quote_notes` still do not approve. No LLM auto-approval. No bearer token on the page.
6. **`GET /` shows a notes `.txt` template** (on-page + download) that instructs the seller how to write customer, `Nx` products, and quantities. The template is instructional, not a stored visit file. It must not include real emails, phones, or tax documents. Seeded catalog names are allowed so the PoC upload can succeed. Note prices in the example are ignored if the user uploads the template as-is.
7. **`GET /` is two columns on a wide viewport.** **Left** (`#notes-flow`): the existing step-by-step process, the notes template, and the upload form. **Right** (`#mcp-connect`): Markdown that teaches operators how to attach Claude Code or Codex to Quote MCP, lists every public slash prompt and tool name with a brief description, and records a future improvement: an in-app LLM API (for example OpenAI) that would turn raw notes into MCP calls. On a narrow viewport, stack with the notes column first. Draft/error panels stay **full width above** the split so POST feedback is not hidden in one column. Do not put bearer tokens, hashes, or `Authorization` values on the page. Examples use placeholders (`${env:MCP_QUOTE_TOKEN}`) and Artisan token create.

The upload control is the primary action on the page. After POST, “nothing happened except the file uploaded” is a failed UX: the seller must see either the draft quote or a specific lookup/validation error.

Do not hide the flow on a separate unlinked URL. This is still not a full seller portal (no quote list, CRM, or login product). It is a single-purpose PoC landing.

**Submit:** `POST` with CSRF (named route suggested: `seller-notes.store`, path `/seller-notes`). Multipart field for a single UTF-8 `.txt` or `.md` file. Same size limit as ingest (suggested 64 KiB). Reject empty, binary, and disallowed extensions.

**Store:** write to the `local` disk:

```text
storage/app/private/quotes/notes/{ulid}.txt
```

Return the seller to `GET /` with flash data: original filename, `storage_path`, and when persist succeeds the **quote report** (public codes, names, quantities, persisted money, status, approval summary). Do not print the file body, emails, phones, or prices copied from the notes file. Money on the page comes from the stored quote only.

**Must not:**

- Put the file on the `public` disk or under `public/`.
- Call `storage:link` for this path.
- Invent `QUO-*` numbers or prices in Blade. Persistence and pricing stay in `CreateQuoteAction` + `QuotePricingService`.
- Auto-approve on upload. Approval is an explicit second POST after the draft is shown.
- Auto-generate a PDF on upload or on approve.
- Add a new MCP approve tool, call `/mcp/quotes` from the browser, or mark `approved` inside `generate_quote_report`.
- Put MCP bearer tokens on the page or in the form.
- Log the raw notes body.

**Authorization:** this is not an anonymous public dump of PII. The POST must run as an authenticated seller Account.

- If the app still has no web login session, fail closed in `production`.
- In `local` / `testing` only, the same `MCP_QUOTE_SELLER_ACCOUNT_CODE` binding as TASK-009 may authorize the home-page upload so the PoC works without building Fortify/Breeze in this task.
- A customer (`CLI-*`) or inactive account must get 403.

Optional extra: `POST /api/quotes/seller-notes` for HTTP MCP clients that cannot use the browser form. That API does **not** replace the welcome-page upload. The welcome form is required.

Listing/deleting historical notes stays out of scope.

## Data

No new tables. No JSON/`metadata` columns on `quotes`. The notes file is ingress only; the quote row remains the commercial source of truth.

Reuse:

- `search_customers`, `search_products`, `generate_quote_report`, `get_quote_report`, `generate_quote_draft_pdf`
- TASK-008 HTTP auth and TASK-009 local stdio seller binding
- TASK-007 payload minimization

Overwrite or unique ULID filenames under `quotes/notes/` as needed. Do not keep a notes history table.

## Privacy (TASK-007)

Inbound notes are **more** dangerous than structured tool args: they often contain phones, documents, and off-catalog prices.

Must:

- Redact emails, phones, and document-like digit strings from **model-facing** ingest output.
- Never return prices extracted from the file.
- Never log the raw notes body, bearer tokens, or file bytes.
- Keep the private file off the public disk.

The original file on private disk may still contain PII; only the briefing is vendor-facing.

## Constraints

### Must

- New Prompt `generate-quote-from-notes` and tool `ingest_seller_quote_notes`.
- **`GET /` is a dedicated notes-to-quote PoC page**. Upload stores the file privately **and** creates a draft when ingest + catalog lookup are unambiguous, then shows persisted money on the page.
- **`GET /` includes a notes `.txt` template** (visible + download) that teaches the file shape. No real PII in the template.
- **`GET /` includes a Markdown MCP client guide** in a **right-hand column** beside the notes flow: Claude Code + Codex examples, slash/tool catalog, future LLM-API ingest. Left column: steps, template, upload. No secrets on the page. Do not implement the OpenAI (or other vendor) client in this task.
- MCP tool `ingest_seller_quote_notes` still does not create, price, or approve quotes.
- `generate_quote_report` still rejects caller-supplied prices and still does not approve.
- `generate_quote_report` still rejects caller-supplied prices. The home-page path never passes note prices into create.
- Ambiguous catalog matches still return candidates/errors and do not persist.
- Tests for ingest parsing, price ignore, PII redaction, path traversal, oversize, authorization, Prompt wording, no raw body in MCP text, welcome-page upload, **and home-page POST creating a draft (or failing closed) and rendering the result**.
- Update `.specs/SOLUTION-OVERVIEW.md` and `explanation.md` build-order / MCP table when implementing.

### Must Not

- Do not OCR images or handwritten scans in this task (photos of notebooks stay out of scope).
- Do not parse Word/Excel as a required format (UTF-8 `.txt` / `.md` is enough; PDF **text layer** is optional, not required).
- Do not let the model treat note prices as catalog prices.
- Do not auto-call `generate_quote_report` from the MCP **ingest tool**.
- Do not HTTP-call `/mcp/quotes` from the browser or put bearer tokens on `GET /`.
- Do not put notes files on the public disk.
- Do not add `user_id` or metadata JSON columns.
- Do not email anyone.
- Do not skip the welcome-page upload in favor of MCP paste-only.
- Do not change REST quote CRUD (create/edit/submit/approve) except an optional notes API that mirrors the web store.
- Do not add an OpenAI (or other LLM HTTP) client, API keys on the page, or auto-generate drafts via a vendor in this task. Document that path as future only.

### In Scope

- Text ingest + briefing + Prompt that drives existing quote tools.
- Private-disk path ingest with traversal protection.
- **Required** notes `.txt` template on the landing (copy/download) plus upload → draft → optional Approve.
- **Required** two-column landing: left = steps + template + upload; right = Markdown MCP connect (Claude/Codex, `/` names, future LLM API).
- Tests and spec walk-through updates.

### Out of Scope

- OCR / vision of photos or scans.
- Audio notes, WhatsApp export parsers, email-in.
- CRM, quote versioning, taxes, shipping.
- A full seller portal (quote list, CRM, Fortify/Breeze, dashboards). Replacing the default Laravel starter with this PoC landing **is** required.
- Storing the original notes as a required column on `quotes`.
- Per-token seller mapping (still TASK-008/009 identity model).
- LLM auto-approval (the model must not approve without a human click on the landing, and MCP prompts still forbid treating generate as approve).
- Calling OpenAI (or any LLM HTTP API) from Laravel to parse notes and invoke MCP. Document that as **future** on `GET /` and in this spec; do not add API keys, vendor SDKs, or a second quote engine in this task.

## Naming

- MCP Prompt: `generate-quote-from-notes`
- user-facing command: `/generate-quote-from-notes`
- MCP Tool: `ingest_seller_quote_notes`
- storage prefix: `quotes/notes/` on disk `local`
- web home: `GET /` (`welcome.blade.php`)
- web template: `GET /seller-notes/template.txt` (`seller-notes.template`)
- landing Markdown: `resources/seller-notes/mcp-connect.md` (rendered on `GET /`)

Existing names stay unchanged (`generate-quote-report`, `generate_quote_report`, …).

## Tasks

### T1: Ingestor

What: `SellerQuoteNotesIngestor` turns UTF-8 text into the briefing shape. Heuristics may be simple (regex for `Nx`, labeled lines). Prefer recall of mentions over perfect NLP. Fail closed on empty input.

Verify:

- sample notes yield customer mention + line candidates with quantities.
- money tokens set `prices_ignored` and do not appear in the briefing.
- email/phone/document-like strings set `pii_redacted` and do not appear in `notes_remainder` or mentions.

### T2: MCP tool

What: `ingest_seller_quote_notes` with `RequiresSellerAccount`, size limit, `text` and/or `storage_path` under `quotes/notes/`.

Verify:

- seller can ingest; non-seller cannot.
- path `quotes/notes/../../../.env` is rejected.
- MCP text/structured result has no raw unsanitized body and no prices.

### T3: Prompt + Cursor command

What: `GenerateQuoteFromNotesPrompt` + `.cursor/commands/generate-quote-from-notes.md`.

Steps: ingest → search until unambiguous → `generate_quote_report` without prices → show persisted money → ask PDF (TASK-012).

Verify tests assert those instructions (same style as `QuoteMcpPromptTest`).

### T4: PoC landing + upload creates draft (required)

What: Replace the default Laravel welcome on `GET /`. `POST /seller-notes` (CSRF) stores UTF-8 `.txt`/`.md` under `storage/app/private/quotes/notes/{ulid}.txt`, runs ingest + catalog resolve, and when unambiguous persists a **draft** via `CreateQuoteAction`. Redirect shows the quote report (number, status, persisted money, not approved). Ambiguous/empty/not-found: no quote; show the error. Never use note prices.

Authorize as seller (local/testing may bind `MCP_QUOTE_SELLER_ACCOUNT_CODE`; production without a seller session fails closed).

Verify:

- `GET /` is the PoC landing. It does not present the default Laravel starter as the page.
- valid notes with unique catalog matches write the file, create one draft, and the redirected page shows `QUO-*`, `unit_price`, `line_total`, `total`, `currency`, and not-approved wording. Note amounts (e.g. `R$ 199`) are not used as `unit_price`.
- ambiguous or missing catalog matches: file may exist, quote count unchanged, error visible, no invented codes.
- empty/oversize/wrong type rejected; no write.
- non-seller / unauthenticated (production rules) cannot store or create.
- response and flash data do not include the raw notes body, emails, or phones.

### T4b: Approve from the landing (required)

What: After “Draft quote created. It is not approved.”, show an Approve button. `POST /seller-notes/quotes/{quote}/approve` (CSRF, seller auth same as upload) calls `SubmitQuoteAction` then `ApproveQuoteAction`. Redirect shows the same report with status `approved`. Do not reprice. Do not call MCP HTTP. Do not add an MCP approve tool. REST `/api/quotes/{quote}/approve` remains customer-gated.

Verify:

- draft page includes Approve; approved page does not.
- one confirm: `draft` → `approved` (`approved_at` set); money unchanged.
- non-owner seller / customer / unauthenticated production: 403; status unchanged.
- `generate_quote_report` / ingest still do not approve.

### T4c: Notes template on the landing (required)

What: `GET /` shows a UTF-8 `.txt` template that instructs how to write notes (one customer, `Nx` product lines, quantities). Same body is downloadable at `GET /seller-notes/template.txt` from `resources/seller-notes/template.txt` (not `public/`). Copy/download controls. No real emails, phones, or documents in the template.

Suggested template body:

```text
INSTRUCTIONS: One catalog company on a Customer line. Each product on its own line as Nx Product Name. Quantities required. Note amounts are ignored. No emails, phones, or tax documents.

Visit 28/08 — Northwind Industrial Ltd
Customer: Northwind Industrial Ltd
2x Access Doors and Panels
4x Safety Rails
1 Vent Covers
They mentioned R$ 199 but I said catalog price
Valid until 2026-09-30
Ship to the usual warehouse
```

Verify:

- `GET /` shows the template body and a download link to `seller-notes.template`.
- `GET /seller-notes/template.txt` returns `text/plain` with that body and a `.txt` filename.
- template is not stored under `quotes/notes/` by merely viewing the page.

### T4d: MCP connect guide on the landing (required)

What: On `GET /`, use a two-column layout (`#landing-split`). **Left** `#notes-flow`: step-by-step list, notes template, upload form. **Right** `#mcp-connect`: render `resources/seller-notes/mcp-connect.md` as HTML (Laravel `Str::markdown` / CommonMark). Quote report and validation errors stay full width **above** the split. Narrow viewports stack, notes column first.

The Markdown must include:

1. **Connect Claude Code** — project `.mcp.json` stdio example (`docker compose exec -T app php artisan mcp:start quotes` and `application`). Reload with `/mcp`. Seller from `MCP_QUOTE_SELLER_ACCOUNT_CODE` on local stdio. Quote tools live under **laravel-quotes**, not `health_check` on **laravel-application**.
2. **Connect Codex** — equivalent local stdio `config.toml` (or current Codex MCP server block) spawning the same Artisan commands; optional HTTP `http://localhost:8890/mcp/quotes` with `Authorization: Bearer ${MCP_QUOTE_TOKEN}` after `php artisan mcp:client-token:create`. No raw token in the file or on the page.
3. **Available `/` prompts and tools** — one short description each for Quote MCP prompts and tools (and `health_check` on the application server). Slash names are MCP prompt names (`/generate-quote-report`, `/generate-quote-from-notes`, `/generate-quote-draft-pdf`), not HTTP routes.
4. **Future improvements** — later work may add an in-app LLM API (for example OpenAI): Laravel would send the raw UTF-8 notes (after private store + PII/price rules) to the model, then the application or an MCP client would call `ingest_seller_quote_notes` → search → `generate_quote_report` to persist a draft. Pricing and `QUO-*` still stay in Laravel. This task does not implement that.

Verify:

- `GET /` shows Claude Code, Codex, each Quote MCP tool name, the three slash prompts, and wording about a future OpenAI-class API.
- Markup has `#landing-split`, `#notes-flow` (contains the template and upload), and `#mcp-connect`.
- On the desktop CSS, the two columns sit **side by side** (`grid-template-columns` with two tracks).
- Response has no bearer token values, `MCP_QUOTE_TOKEN=` assignments, or hashes.

### T5: Specs

What: Update `.specs/SOLUTION-OVERVIEW.md` and `explanation.md`:

- new Prompt/tool in the MCP table
- walk-through: upload → draft on page → optional Approve via submit+approve actions
- landing Markdown: two-column `GET /` (left notes flow, right Claude/Codex connect, slash/tool catalog, future LLM API)
- out of scope: OCR; in-app OpenAI client (document only)

## Validation

The task is complete when:

1. `GET /` is the notes-to-quote PoC landing. Upload stores the file privately and, when the briefing resolves uniquely, creates a **draft** and shows persisted money (not approved) plus an **Approve** control. Ambiguous notes do not persist.
2. The same file (or pasted UTF-8) can still be ingested through Quote MCP into a briefing **without** the ingest tool creating a quote.
3. Prices and obvious PII from the file do not appear as quote prices or as PII on the welcome page.
4. `/generate-quote-from-notes` still instructs the model to resolve via search tools and persist only through `generate_quote_report`.
5. The home-page draft uses TASK-010 money from storage. PDF remains a separate later step (TASK-012); upload does not auto-save a PDF.
6. Clicking Approve submits then approves through existing actions; the page shows `approved`. Upload and generate still do not auto-approve. No new MCP approve tool. REST approve stays customer-only.
7. Relevant tests pass (including a feature test that `POST /seller-notes` creates a draft or fails closed, and that landing approve transitions status).
8. `GET /` shows the notes `.txt` template and a download; viewing it does not create a quote.
9. `GET /` is a two-column landing: left notes flow (steps, template, upload) and right Markdown MCP guide (Claude Code + Codex, slash/tool catalog, future LLM API), with no secrets on the page.

## Future improvements (not this task)

**In-app LLM API (OpenAI or similar).** Today the landing uses regex ingest + catalog lookup, or a human MCP client (Claude Code / Codex) that calls tools. A later task may:

1. Keep storing the UTF-8 notes file on the private disk (same as now).
2. Send a **minimized** notes payload to an LLM HTTP API (not the raw PII-laden file if TASK-007 still applies).
3. Have that model (or Laravel after the model) drive Quote MCP: `ingest_seller_quote_notes` and/or `search_customers` / `search_products`, then `generate_quote_report` with codes and quantities only.
4. Show the persisted draft on `GET /` as today. Do not treat the vendor as the price source. Do not auto-approve.

That path needs vendor keys in env (never on the page), fail-closed when the API is down, and the same unambiguous-lookup rules. It is **out of scope** for TASK-013 implementation. Document it on the landing and here so the next task has a written intent.

## Relation to earlier tasks

| Task | What it did | This task |
| --- | --- | --- |
| TASK-005 | Conversational collect → search → generate | Same generate path; **file** is the collect step |
| TASK-007 | Minimize outbound PII | Also sanitize **inbound notes** before the vendor |
| TASK-010 | Show persisted money on the draft | Unchanged after generate |
| TASK-012 | Ask then private PDF save | Unchanged after generate |
| TASK-013 (this) | PoC landing upload → ingest → shared generate → draft on the page; MCP ingest tool stays read-only | New |
