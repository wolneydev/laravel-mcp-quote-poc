<?php

namespace App\Actions\Quotes;

use App\Mcp\Exceptions\QuoteLookupException;
use App\Mcp\Lookups\CustomerLookup;
use App\Mcp\Lookups\ProductLookup;
use App\Mcp\Lookups\SellerAccountLookup;
use App\Mcp\Support\QuoteReportPresenter;
use App\Mcp\Support\SellerQuoteNotesIngestor;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Quote;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateQuoteFromSellerNotesAction
{
    public function __construct(
        private SellerQuoteNotesIngestor $ingestor,
        private SellerAccountLookup $sellers,
        private CustomerLookup $customers,
        private ProductLookup $products,
        private CreateQuoteAction $createQuote,
        private QuoteReportPresenter $presenter,
    ) {}

    /**
     * @return array{
     *     filename: string,
     *     storage_path: string|null,
     *     quote_report: array<string, mixed>
     * }
     */
    public function handle(Account $seller, string $text, string $filename, ?string $storagePath = null): array
    {
        Gate::authorize('create', Quote::class);

        $briefing = $this->ingestor->ingest($text);

        if ($briefing['customer_mentions'] === [] || $briefing['line_candidates'] === []) {
            throw ValidationException::withMessages([
                'notes' => '[empty_briefing] The notes file has no usable customer or product mentions.',
            ]);
        }

        try {
            $sellerAccount = $this->sellers->resolve(null, $seller);
            $customer = $this->resolveSingleCustomer($briefing['customer_mentions']);
            $items = $this->resolveItems($briefing['line_candidates']);
        } catch (QuoteLookupException $exception) {
            throw $exception->toValidationException();
        }

        $quote = $this->createQuote->handle(
            sellerAccount: $sellerAccount,
            customerAccount: $customer->account,
            items: $items,
            validUntil: $this->isoDateHint($briefing['valid_until_hint']),
            notes: $briefing['notes_remainder'] !== '' ? $briefing['notes_remainder'] : null,
        );

        $report = $this->presenter->present($quote);
        unset($report['markdown']);

        return [
            'filename' => $filename,
            'storage_path' => $storagePath,
            'quote_report' => $report,
        ];
    }

    /**
     * @param  list<string>  $mentions
     */
    private function resolveSingleCustomer(array $mentions): Customer
    {
        $resolved = [];

        foreach ($mentions as $mention) {
            $customer = $this->customers->resolve($mention);
            $resolved[$customer->id] = $customer;
        }

        if (count($resolved) !== 1) {
            throw ValidationException::withMessages([
                'notes' => '[ambiguous_customer] Notes mention more than one customer. Keep a single quote-ready customer.',
            ]);
        }

        return array_values($resolved)[0];
    }

    /**
     * @param  list<array{product_mention: string, quantity: int|float|null}>  $candidates
     * @return list<array{product_id: int, quantity: int|float}>
     */
    private function resolveItems(array $candidates): array
    {
        $resolved = [];
        $productIds = [];
        $currencies = [];

        foreach ($candidates as $index => $candidate) {
            if ($candidate['quantity'] === null || (float) $candidate['quantity'] <= 0) {
                throw ValidationException::withMessages([
                    'notes' => '[missing_quantity] A product mention is missing a quantity.',
                ]);
            }

            $product = $this->products->resolve($candidate['product_mention']);

            if (in_array($product->id, $productIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.product" => '[repeated_product] A product cannot be repeated in the same quote.',
                ]);
            }

            $productIds[] = $product->id;
            $currencies[$product->currency] = true;
            $resolved[] = [
                'product_id' => $product->id,
                'quantity' => $candidate['quantity'],
            ];
        }

        if (count($currencies) > 1) {
            throw ValidationException::withMessages([
                'items' => '[mixed_currency] All products in a quote must use the same currency.',
            ]);
        }

        return $resolved;
    }

    private function isoDateHint(?string $hint): ?string
    {
        if ($hint === null) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hint) !== 1) {
            return null;
        }

        return $hint;
    }
}
