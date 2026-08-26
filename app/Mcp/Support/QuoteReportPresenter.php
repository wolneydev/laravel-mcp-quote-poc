<?php

namespace App\Mcp\Support;

use App\Enums\QuoteStatus;
use App\Models\Quote;

class QuoteReportPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(Quote $quote): array
    {
        $quote->loadMissing([
            'sellerAccount',
            'customerAccount.customer',
            'items',
        ]);

        $status = $quote->status instanceof QuoteStatus
            ? $quote->status->value
            : (string) $quote->status;

        $isApproved = $quote->status === QuoteStatus::Approved;
        $customer = $quote->customerAccount?->customer;

        $report = [
            'quote_id' => $quote->id,
            'quote_number' => $quote->number,
            'status' => $status,
            'created_at' => $quote->created_at?->toIso8601String(),
            'valid_until' => $quote->valid_until?->toDateString(),
            'seller' => [
                'account_id' => $quote->sellerAccount?->id,
                'account_code' => $quote->sellerAccount?->code,
                'name' => $quote->sellerAccount?->name,
            ],
            'customer' => [
                'customer_id' => $customer?->id,
                'customer_code' => $customer?->code,
                'customer_name' => $customer?->name,
                'account_id' => $quote->customerAccount?->id,
                'account_code' => $quote->customerAccount?->code,
            ],
            'items' => $quote->items->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'product_code' => $item->product_code,
                'product_name' => $item->product_name,
                'unit' => $item->unit,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
            ])->values()->all(),
            'total' => $quote->total,
            'currency' => $quote->currency,
            'notes' => $quote->notes,
            'approval_summary' => $isApproved
                ? "Quote {$quote->number} is approved."
                : "Quote {$quote->number} has status {$status} and is not approved.",
        ];

        $report['markdown'] = $this->markdown($report);

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function markdown(array $report): string
    {
        $lines = [
            "# Quote {$report['quote_number']}",
            '',
            "- Status: {$report['status']}",
            "- Seller: {$report['seller']['name']} ({$report['seller']['account_code']})",
            "- Customer: {$report['customer']['customer_name']} ({$report['customer']['customer_code']})",
            "- Total: {$report['currency']} {$report['total']}",
            "- {$report['approval_summary']}",
            '',
            '| Product | Qty | Unit price | Line total |',
            '| --- | --- | --- | --- |',
        ];

        foreach ($report['items'] as $item) {
            $lines[] = "| {$item['product_code']} {$item['product_name']} | {$item['quantity']} | {$item['unit_price']} | {$item['line_total']} |";
        }

        return implode("\n", $lines);
    }
}
