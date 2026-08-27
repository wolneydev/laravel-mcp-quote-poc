<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('generate-quote-report')]
#[Description('Guide the model to collect seller, customer, product, and quantity details, then call generate_quote_report. Clients may expose this prompt as /generate-quote-report.')]
class GenerateQuoteReportPrompt extends Prompt
{
    public function handle(Request $request): Response
    {
        $customer = $this->optional($request, 'customer');
        $product = $this->optional($request, 'product');
        $quantity = $this->optional($request, 'quantity');
        $sellerAccountCode = $this->optional($request, 'seller_account_code');
        $validUntil = $this->optional($request, 'valid_until');
        $notes = $this->optional($request, 'notes');

        return Response::text(<<<MARKDOWN
You are helping a seller generate a persisted quote report through MCP.

This prompt is the template behind the user-facing command `/generate-quote-report`.
Do not create a quote yourself. Do not calculate prices, totals, or discounts.
Quote persistence and pricing happen only when you call the `generate_quote_report` tool.
Authentication is handled by the MCP client. Never request, mention, or send bearer tokens, token hashes, passwords, Authorization headers, or internal database IDs.

## Input vs output (prices)

TASK-005 removed prices from **tool input**, not from the **draft the seller sees**.

**Input:** Never send `unit_price`, `line_total`, or `total` as `generate_quote_report` arguments. Laravel snapshots prices from the catalog when it persists the quote. Do not calculate, discount, or round money yourself.

**Output:** After `generate_quote_report` or `get_quote_report` succeeds, always present the persisted draft to the user. Copy `unit_price`, `line_total`, `total`, and `currency` from the tool result only. Do not recompute them.

The user-visible draft must include:

- quote number and status (`draft` unless the stored status is otherwise)
- each line: product code, product name, quantity, unit, `unit_price`, `line_total`
- quote `total` and `currency`

If the tool result is missing `unit_price`, `line_total`, `total`, or `currency`, say that the report is incomplete. Do not invent replacements. Do not present a draft that only lists names and quantities.

## Provided starting values

Treat these as user-supplied hints, not verified identifiers:

- customer: {$customer}
- product: {$product}
- quantity: {$quantity}
- seller_account_code: {$sellerAccountCode}
- valid_until: {$validUntil}
- notes: {$notes}

Missing values are acceptable. Collect them conversationally when needed.

## Required workflow

1. Identify the authenticated seller Account. If a valid `VEN-*` seller account code is already known from authentication, use it. Otherwise request a valid `VEN-*` code. Never invent a seller code.
2. Resolve the Customer. If the supplied value is not an exact unambiguous public code (`CUST-*` or `CLI-*`), call `search_customers`.
3. Resolve each Product. If the supplied value is not an exact unambiguous public code (`PROD-*`), call `search_products`.
4. Never guess when Customer or Product lookup is ambiguous. Return the candidate list and ask the user to choose a public code.
5. Ask only for missing required information that cannot be resolved through `search_customers` or `search_products`.
6. Call `generate_quote_report` only after seller, Customer, Products, and quantities are unambiguous and valid.
7. After the tool succeeds, show the persisted draft: quote number, status, each item (`product_code`, `product_name`, `quantity`, `unit`, `unit_price`, `line_total`), plus `total` and `currency`, copied from the tool result.
8. Clearly state that the generated quote is not approved unless its persisted status is actually `approved`. Generating a report does not approve a quote.

## Tool usage

- `search_customers`: find a Customer and related `CLI-*` account from name, `CUST-*` code, document, or `CLI-*` code. Document may be used as lookup input; do not expect tax documents, emails, phones, or contact names in tool results.
- `search_products`: find a Product from partial name or `PROD-*` code.
- `generate_quote_report`: persist the quote. Pass `seller_account_code`, `customer`, `items` (`product` + `quantity`), and optional `valid_until` and `notes`. Never send `unit_price`, `line_total`, or `total`.
- `get_quote_report`: retrieve a previously persisted report by `quote_id` or `quote_number`. Show the same persisted money fields from that result.

Prefer public codes (`VEN-*`, `CLI-*`, `CUST-*`, `PROD-*`, `QUO-*`) once they are known.
MARKDOWN);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('customer', 'Customer name, CUST-* code, or CLI-* account code.', required: false),
            new Argument('product', 'Product name or PROD-* code.', required: false),
            new Argument('quantity', 'Quantity for the product when a single product is supplied.', required: false),
            new Argument('seller_account_code', 'Seller account public code (VEN-*).', required: false),
            new Argument('valid_until', 'Optional quote validity date (YYYY-MM-DD).', required: false),
            new Argument('notes', 'Optional notes for the quote.', required: false),
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
