# MCP Quote PDF Private Save After Report

## Why

TASK-011 added Laravel PDF rendering (`QuoteDraftPdfRenderer`, `generate_quote_draft_pdf`, `/generate-quote-draft-pdf`) and a signed download URL. The conversational draft from `/generate-quote-report` still only offers a PDF **if the seller happens to ask**. The tool also does not write a file under the private disk.

Sellers need a clear last step after the report: **ask** whether they want a PDF quote saved, and if they say yes, **persist** that PDF on Laravel’s private storage (`storage/app/private`), not only regenerate it later through a signed GET.

The PDF remains a rendering of stored quote snapshots. Saving it does not approve the quote, reprice it, or invent a second document.

## What

Change the `/generate-quote-report` Prompt so that, after a successful `generate_quote_report` (draft shown with persisted money), the model **must ask** the user whether they want to save a PDF quote file.

If the user answers yes, call the existing `generate_quote_draft_pdf` tool. That tool must write the binary to the **local/private disk** and return structured metadata (quote number, status, total, currency, filename, storage path, optional short-lived download URL). Do not paste PDF bytes into the chat.

If the user answers no, stop. Do not generate or store a PDF.

Keep `/generate-quote-draft-pdf` for sellers who already have a `QUO-*` and want a file without creating a new quote.

Suggested structure (reuse, do not duplicate the renderer):

- `app/Mcp/Prompts/GenerateQuoteReportPrompt.php` — required ask after the draft
- `app/Mcp/Support/QuoteDraftPdfRenderer.php` (or a small store helper) — put the binary on the private disk
- `app/Mcp/Tools/GenerateQuoteDraftPdfTool.php` — persist + return path metadata
- `.cursor/commands/generate-quote-draft-pdf.md` and the generate-quote-report command, if present — same ask-then-save wording

Do not add a new MCP tool unless storage cannot be done inside `generate_quote_draft_pdf`. Creating a quote and saving a PDF stay two tool calls.

REST quote CRUD stays in `routes/api.php`. The existing signed GET `quotes.draft-pdf` may remain as a convenience download of the same rendering; the **source of truth for “saved file”** is the private disk file created on consent.

## LLM Command: `/generate-quote-report` (extended)

### Goal

After the persisted conversational draft is shown, always offer a PDF save. Only write a file when the seller confirms.

The command still maps to MCP Prompt `generate-quote-report`.

### Required LLM behavior (add after TASK-010 / TASK-011 draft steps)

When `/generate-quote-report` is invoked, after `generate_quote_report` succeeds and the user-visible draft is shown (quote number, status, line money, total, currency, not-approved wording), the prompt must tell the LLM to:

1. Ask a clear yes/no question: whether the seller wants to **save a PDF file** of this quote.
2. Wait for the user’s answer. Do not call `generate_quote_draft_pdf` in the same turn as quote creation unless the user already said they want a PDF in that conversation.
3. If the answer is **yes** (or an unambiguous equivalent: save, generate PDF, download, store the file): call `generate_quote_draft_pdf` with the persisted `quote_number` (and/or `quote_id`) from the tool result. Never invent a quote number.
4. After the PDF tool succeeds, tell the user the file was saved on the application private storage. Copy `quote_number`, `status`, `total`, `currency`, `filename`, and storage path (or `download_url` if returned) from the tool result only. Do not paste file bytes or base64.
5. If the answer is **no** (or skip, not now): do not call the PDF tool. The conversational draft is enough.
6. Clearly state that saving a PDF does not approve the quote unless stored status is already `approved`.
7. Never write PDF markup, never calculate money, never reconstruct the document from Markdown.

### Example

```text
/generate-quote-report
Customer: Acme Ltd
Product: Premium Keyboard
Quantity: 3
```

Then, after Laravel returns `QUO-2026-000001` as a draft with money:

```text
Quote QUO-2026-000001 is saved as a draft (not approved).
Do you want to save a PDF file of this quote?
```

User: `yes`

Model calls `generate_quote_draft_pdf` with `quote_number=QUO-2026-000001`. Laravel writes `storage/app/private/quotes/drafts/QUO-2026-000001-draft.pdf` (or equivalent unique name on the `local` disk).

## Data

No new tables. Do not add a required `pdf_path` column on `quotes`. The file on disk is generated on consent; the quote row remains the commercial source of truth.

Reuse:

- `QuoteDraftPdfRenderer` + presenter snapshots (TASK-011)
- `QuotePolicy::view`
- TASK-008 HTTP auth and TASK-009 local stdio seller binding

Overwrite an existing file for the same quote number when the seller asks again. Do not keep a PDF history table (still out of scope from TASK-011).

## Storage

Use the Laravel `local` disk, whose root is `storage/app/private` (not `storage/app/public`, not `public/`).

Suggested object key:

```text
quotes/drafts/{quote_number}-draft.pdf
```

That resolves to:

```text
storage/app/private/quotes/drafts/{quote_number}-draft.pdf
```

Must:

- Create parent directories as needed.
- Write the DomPDF binary from presenter data, not live `products.price`.
- Fail closed if presenter money fields are missing (existing renderer/presenter behavior).
- Not log the PDF binary or bearer tokens.

The MCP structured result should include at least:

- `quote_number`, `status`, `total`, `currency`, `filename`
- `storage_disk` (`local`) and `storage_path` (object key, not a public URL)
- optional `download_url` (existing short-lived signed route) so the seller can fetch the same document without the model seeing bytes

Do not put the file on the `public` disk. Do not `storage:link` this path.

## Privacy (TASK-007)

Unchanged from TASK-011: omit customer `document`, `email`, `phone`, `contact_name`; omit passwords and MCP tokens; never put PDF binary content in model-facing tool text.

## Authentication and authorization

Unchanged: a seller who cannot `view` the quote must not receive or persist a PDF.

Writing the file must happen only after `Gate::authorize('view', $quote)`. Do not leave files on disk for quotes the caller cannot see.

## Constraints

### Must

- After a successful `generate_quote_report` in the `generate-quote-report` Prompt, **ask** whether to save a PDF. This is required prompt behavior, not optional wording.
- Call `generate_quote_draft_pdf` only after explicit consent (or an earlier explicit PDF request in the same conversation).
- Persist the PDF on the `local` disk under `storage/app/private/...` when that tool runs.
- Leave `quotes.status` unchanged.
- Keep TASK-005 input rejection of prices on `generate_quote_report`.
- Keep TASK-010: show persisted money on the chat draft **before** asking about the PDF.
- Keep TASK-011: Laravel renders; the model does not invent money or PDF markup.
- Add tests for Prompt wording (must ask; must not auto-save), private-disk write, authorization, unchanged status, and no PDF bytes in the MCP text result.
- Update `.specs/SOLUTION-OVERVIEW.md` walk-through: after the draft, the model asks; on yes, Laravel stores the PDF on the private disk.

### Must Not

- Do not auto-save a PDF on every `generate_quote_report`.
- Do not merge PDF generation into `generate_quote_report` (still two calls).
- Do not write to the public disk or `public/storage`.
- Do not add `user_id` or JSON/`metadata` columns.
- Do not mark the quote `approved` because a file was saved.
- Do not email the PDF.
- Do not instruct the model to reconstruct the PDF from Markdown.
- Do not log PDF binaries or tokens.

### In Scope

- Prompt: ask-then-save after `/generate-quote-report`.
- Persist PDF bytes on `storage/app/private` via `generate_quote_draft_pdf`.
- Structured metadata including storage path.
- Tests and SOLUTION-OVERVIEW update.

### Out of Scope

- New PDF layout, letterhead, signatures, or extra languages.
- Email or messaging delivery.
- Taxes, shipping, discounts.
- PDF history as a domain table.
- Changing quote create/update/approve REST flows except using the existing download route if already present.
- Replacing DomPDF or adding a browser renderer.

## Current State

Implemented today (TASK-011):

- `generate_quote_draft_pdf` returns metadata + signed `download_url`.
- `QuoteDraftPdfRenderer` builds the binary in memory; it does not `Storage::put`.
- `GenerateQuoteReportPrompt` step 9: call the PDF tool **if the user asks**; it does not require the model to **ask** after every successful report.

Missing:

- required yes/no ask at the end of `/generate-quote-report`
- write of the PDF file under `storage/app/private`

## Naming

Unchanged:

- MCP Prompt (report): `generate-quote-report`
- user-facing command: `/generate-quote-report`
- MCP Tool (PDF): `generate_quote_draft_pdf`
- MCP Prompt (PDF-only): `generate-quote-draft-pdf`

Storage:

- disk: `local`
- key prefix: `quotes/drafts/`

## Tasks

### T1: Persist the PDF on the private disk

What: When `generate_quote_draft_pdf` succeeds, write the renderer output with `Storage::disk('local')` to `quotes/drafts/{filename}`.

Return `storage_disk`, `storage_path`, `filename`, and existing money/status metadata. Keep the signed URL if useful; it is not a substitute for writing the file.

Verify:

- file exists at `storage/app/private/quotes/drafts/{quote_number}-draft.pdf` (or the agreed filename).
- file starts with `%PDF`.
- amounts match snapshots, not a later `products.price` change.
- no write to `storage/app/public`.
- MCP text/structured output still has no PDF bytes / `%PDF`.

### T2: Ask after `/generate-quote-report`

What: Update `GenerateQuoteReportPrompt` (and the Cursor command for that prompt if it exists):

- After steps that show the persisted draft and the not-approved wording, **ask** if the user wants to save a PDF file.
- Call `generate_quote_draft_pdf` only on yes (or if they already asked for a PDF).
- On success, say the file was saved privately; copy metadata from the tool; never paste bytes.

Keep `/generate-quote-draft-pdf` for an existing `QUO-*` without creating a quote. That path also writes to the same private folder.

Verify:

- Prompt text requires the ask.
- Prompt text forbids auto-save on every report.
- Prompt still requires showing persisted money before the PDF question.
- Prompt still forbids sending prices as input.

### T3: Tests

Cover:

- PDF tool writes to the `local` disk and does not change `status`.
- unauthorized seller does not create a file.
- structured result includes `storage_path` and omits TASK-007 fields and `%PDF`.
- `GenerateQuoteReportPrompt` asserts the yes/no save question and “do not auto-generate unless the user agrees.”

Suggested:

```bash
php artisan test --compact --filter=GenerateQuoteDraftPdf
php artisan test --compact --filter=QuoteMcpPrompt
php artisan test --compact --filter=QuoteDraftPdfRenderer
```

Use `Storage::fake('local')` in tests where that still proves the put path; also assert the real key convention.

### T4: Specs

What: Update `.specs/SOLUTION-OVERVIEW.md`:

- walk-through: after the draft, the model asks to save a PDF; on yes, Laravel writes `storage/app/private/quotes/drafts/...`
- flowchart: `Report` → ask save PDF → yes → `generate_quote_draft_pdf` → private disk (not only “seller happens to ask later”)

## Validation

The task is complete when:

1. `/generate-quote-report` instructs the model to show the money draft, then **ask** to save a PDF, then call `generate_quote_draft_pdf` only on consent.
2. A consented PDF call writes a real PDF under `storage/app/private` on the `local` disk.
3. The file is not on the public disk; chat does not contain PDF bytes; TASK-007 fields stay omitted.
4. Saving a PDF does not approve or reprice the quote.
5. Relevant tests pass.
6. SOLUTION-OVERVIEW describes the ask-then-private-save flow.

## Relation to earlier tasks

| Task | What it did | This task |
| --- | --- | --- |
| TASK-010 | User-visible draft must show persisted money | Still required **before** the PDF question |
| TASK-011 | Laravel renders a printable draft; optional PDF if the user asks; signed URL | Required **ask** after the report; **persist** the file on the private disk |
| TASK-012 (this) | Ask + private save | New |
