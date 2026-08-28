<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('generate-quote-draft-pdf')]
#[Description('Guide the model to request a Laravel-generated PDF of an existing persisted quote. Clients may expose this prompt as /generate-quote-draft-pdf.')]
class GenerateQuoteDraftPdfPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $quoteNumber = $this->optional($request, 'quote_number');
        $quoteId = $this->optional($request, 'quote_id');

        return Response::text(<<<MARKDOWN
You are helping a seller save a printable PDF of an existing persisted quote to private storage.

This prompt is the template behind the user-facing command `/generate-quote-draft-pdf`.
Laravel renders the PDF from stored quote snapshots and writes it under the application private disk. You only request it. Do not write PDF markup, do not reconstruct a document from Markdown, and do not paste file bytes or base64 into the chat.

## Input vs output

**Input:** Identify the quote by `QUO-*` number or persisted `quote_id`. Never invent a quote number. Never send `unit_price`, `line_total`, `total`, or PDF bytes to any quote tool.

**Output:** Money on the PDF comes from Laravel. After `generate_quote_draft_pdf` succeeds, tell the user the file was saved on the application private storage. Copy `quote_number`, `status`, `total`, `currency`, `filename`, and storage path (or `download_url` if returned) from the tool result only. Do not calculate, discount, or round money. Do not invent replacements if those fields are missing.

## Provided starting values

Treat these as user-supplied hints, not verified identifiers:

- quote_number: {$quoteNumber}
- quote_id: {$quoteId}

## Required workflow

1. Identify the quote by `QUO-*` number or persisted `quote_id`. If neither is known, call `get_quote_report` only after the user supplies an identifier, or reuse the identifier from the last successful `generate_quote_report` in this conversation.
2. Never invent a quote number.
3. Call `generate_quote_draft_pdf` with that identifier.
4. Tell the user the file was saved on the application private storage, including quote number, status, total, currency, filename, and storage path copied from the tool result. Mention `download_url` if returned. Do not paste the file.
5. Clearly state that saving a PDF does not approve the quote unless stored status is already `approved`.
6. Never write PDF markup, never calculate money, never paste file bytes or base64 into the chat.

## Tool usage

- `get_quote_report`: load a persisted report when you need to confirm the identifier. Show persisted money from that result; do not reprice.
- `generate_quote_draft_pdf`: ask Laravel to render the PDF and write it to private storage. Pass `quote_id` and/or `quote_number`. Never send prices.

Creating a quote remains `generate_quote_report`. Do not merge PDF generation into quote creation.
MARKDOWN);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('quote_number', 'Public quote number (QUO-*).', required: false),
            new Argument('quote_id', 'Persisted quote ID.', required: false),
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
