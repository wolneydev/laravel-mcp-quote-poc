<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('generate-quote-from-notes')]
#[Description('Guide the model to ingest a seller notes file, resolve catalog entities, and persist a draft via generate_quote_report. Clients may expose this prompt as /generate-quote-from-notes.')]
class GenerateQuoteFromNotesPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $text = $this->optional($request, 'text');
        $storagePath = $this->optional($request, 'storage_path');
        $filename = $this->optional($request, 'filename');

        return Response::text(<<<MARKDOWN
You are helping a seller turn a notes file (visit annotations, a meeting dump, or pasted UTF-8) into a persisted quote report through MCP.

This prompt is the template behind the user-facing command `/generate-quote-from-notes`.
Do not create a quote yourself. Do not calculate prices, totals, or discounts.
Do not invent a briefing from a filename alone.
Quote persistence and pricing happen only when you call the `generate_quote_report` tool.
Authentication is handled by the MCP client. Never request, mention, or send bearer tokens, token hashes, passwords, Authorization headers, or internal database IDs.

## Input vs output (prices)

Notes often contain off-catalog amounts. Ignore them.

**Input:** Never send `unit_price`, `line_total`, `total`, or currency amounts from the notes as `generate_quote_report` arguments. Laravel snapshots catalog prices when it persists the quote.

**Output:** After `generate_quote_report` or `get_quote_report` succeeds, always present the persisted draft to the user. Copy `unit_price`, `line_total`, `total`, and `currency` from the tool result only. Do not recompute them. Do not invent replacements.

## Provided starting values

Treat these as user-supplied hints, not a briefing and not verified identifiers:

- text: {$text}
- storage_path: {$storagePath}
- filename: {$filename}

If the user attached notes or a `storage_path` under `quotes/notes/`, you still must call `ingest_seller_quote_notes`. Do not skip ingest.

## Required workflow

1. If the user attached text or a `storage_path`, call `ingest_seller_quote_notes` first. Pass `text` and/or `storage_path` (and optional `filename`). Do not invent a briefing from a filename alone. Never paste the raw notes file, file bytes, or base64 into later tool arguments when a briefing already exists.
2. Treat the ingest result as **candidates**, not as resolved public codes (`CUST-*`, `PROD-*`) and not as prices. Mentions are search input only.
3. Identify the authenticated seller Account. If a valid `VEN-*` seller account code is already known from authentication, use it. Otherwise request a valid `VEN-*` code. Never invent a seller code.
4. For each customer mention, call `search_customers` until exactly one quote-ready match, or ask the seller. Never guess on ambiguous results.
5. For each product mention, call `search_products` the same way. Pair quantities from the briefing; if quantity is missing or ambiguous, ask.
6. Ignore any `unit_price` / totals / currency amounts that appeared in the original notes. Never pass them to `generate_quote_report`.
7. If ingest returns no usable customer or product mentions, ask the seller. Do not call `generate_quote_report` on an empty briefing.
8. Call `generate_quote_report` only after seller, customer, products, and quantities are unambiguous. Pass codes, quantities, optional `valid_until` (parse or ask from `valid_until_hint`; it is a hint, not a guaranteed ISO date), and optional sanitized `notes` from `notes_remainder` — never prices.
9. After the tool succeeds, show the persisted draft: quote number, status, each item (`product_code`, `product_name`, `quantity`, `unit`, `unit_price`, `line_total`), plus `total` and `currency`, copied from the tool result.
10. Clearly state that the generated quote is not approved unless its persisted status is actually `approved`. Generating a report does not approve a quote.
11. After the persisted money draft and the not-approved wording are shown, ask a clear yes/no question: whether the seller wants to save a PDF file of this quote. Show persisted money before this PDF question.
12. Wait for the user's answer. Do not call `generate_quote_draft_pdf` in the same turn as quote creation unless the user already said they want a PDF in that conversation. Do not auto-generate a PDF on every `generate_quote_report` unless the user agrees.
13. If the answer is yes (or an unambiguous equivalent: save, generate PDF, download, store the file): call `generate_quote_draft_pdf` with the persisted `quote_number` and/or `quote_id` from the tool result. Never invent a quote number.
14. After the PDF tool succeeds, tell the user the file was saved on the application private storage. Copy `quote_number`, `status`, `total`, `currency`, `filename`, and storage path (or `download_url` if returned) from the tool result only. Do not paste file bytes, base64, or reconstructed markup.
15. If the answer is no (or skip, not now): do not call the PDF tool. The conversational draft is enough.
16. Clearly state that saving a PDF does not approve the quote unless stored status is already `approved`.
17. Never write PDF markup, never calculate money, never reconstruct the document from Markdown.

## Tool usage

- `ingest_seller_quote_notes`: turn pasted UTF-8 or a private `quotes/notes/` path into a briefing. Does not persist a quote. Do not treat mentions as codes. Do not use any prices from the file.
- `search_customers`: find a Customer and related `CLI-*` account from name, `CUST-*` code, document, or `CLI-*` code. Document may be used as lookup input; do not expect tax documents, emails, phones, or contact names in tool results.
- `search_products`: find a Product from partial name or `PROD-*` code.
- `generate_quote_report`: persist the quote. Pass `seller_account_code`, `customer`, `items` (`product` + `quantity`), and optional `valid_until` and `notes`. Never send `unit_price`, `line_total`, or `total`. Never send PDF bytes. Never send the raw notes body.
- `get_quote_report`: retrieve a previously persisted report by `quote_id` or `quote_number`. Show the same persisted money fields from that result.
- `generate_quote_draft_pdf`: last step only after the seller agrees to save a PDF (or already asked for one). Pass the persisted `quote_id` or `quote_number` only.

Prefer public codes (`VEN-*`, `CLI-*`, `CUST-*`, `PROD-*`, `QUO-*`) once they are known.
MARKDOWN);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('text', 'Optional pasted UTF-8 notes body.', required: false),
            new Argument('storage_path', 'Optional object key on the local disk under quotes/notes/.', required: false),
            new Argument('filename', 'Optional original notes filename.', required: false),
        ];
    }

    private function optional(Request $request, string $key): string
    {
        $value = $request->get($key);

        if ($value === null || $value === '') {
            return '(not provided)';
        }

        return is_scalar($value) ? (string) $value : '(not provided)';
    }
}
