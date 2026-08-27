<?php

namespace App\Mcp\Tools;

use App\Actions\Quotes\CreateQuoteAction;
use App\Mcp\Concerns\RequiresSellerAccount;
use App\Mcp\Exceptions\QuoteLookupException;
use App\Mcp\Lookups\CustomerLookup;
use App\Mcp\Lookups\ProductLookup;
use App\Mcp\Lookups\SellerAccountLookup;
use App\Mcp\Support\QuoteReportPresenter;
use App\Models\Quote;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('generate_quote_report')]
#[Description('Resolve seller, customer, and product public identifiers, persist a draft quote using the shared quote domain, and return the quote report. Does not approve the quote.')]
class GenerateQuoteReportTool extends Tool
{
    use RequiresSellerAccount;

    public function __construct(
        private SellerAccountLookup $sellers,
        private CustomerLookup $customers,
        private ProductLookup $products,
        private CreateQuoteAction $createQuote,
        private QuoteReportPresenter $presenter,
    ) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'seller_account_code' => $schema->string()
                ->description('Seller account public code (VEN-*). Optional when it can be inferred from the authenticated seller.')
                ->min(1)
                ->max(32),
            'customer' => $schema->string()
                ->description('Customer code (CUST-*), customer account code (CLI-*), unique customer name, or tax/company document. Document is an input only and is never echoed back.')
                ->min(1)
                ->max(255)
                ->required(),
            'items' => $schema->array()
                ->description('Quote line items. Each product may appear only once. All products must share the same currency.')
                ->min(1)
                ->items($schema->object([
                    'product' => $schema->string()
                        ->description('Product code (PROD-*) or a unique product name.')
                        ->min(1)
                        ->max(255)
                        ->required(),
                    'quantity' => $schema->number()
                        ->description('Quantity greater than zero. Caller-provided prices are rejected.')
                        ->min(0.01)
                        ->required(),
                ]))
                ->required(),
            'valid_until' => $schema->string()
                ->description('Optional quote validity date (YYYY-MM-DD).'),
            'notes' => $schema->string()
                ->description('Optional notes for the quote.')
                ->max(65535),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $this->sellerAccount($request);

        $validated = $request->validate([
            'seller_account_code' => ['nullable', 'string', 'max:32'],
            'customer' => ['required', 'string', 'min:1', 'max:255'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product' => ['required', 'string', 'min:1', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['prohibited'],
            'items.*.line_total' => ['prohibited'],
            'items.*.total' => ['prohibited'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:65535'],
            'currency' => ['prohibited'],
            'total' => ['prohibited'],
            'unit_price' => ['prohibited'],
            'line_total' => ['prohibited'],
            'status' => ['prohibited'],
            'number' => ['prohibited'],
        ]);

        try {
            $seller = $this->sellers->resolve(
                $validated['seller_account_code'] ?? null,
                $request->user(),
            );
            $customer = $this->customers->resolve($validated['customer']);
            $resolvedItems = $this->resolveItems($validated['items']);
        } catch (QuoteLookupException $exception) {
            throw $exception->toValidationException();
        }

        Gate::authorize('create', Quote::class);

        $quote = $this->createQuote->handle(
            sellerAccount: $seller,
            customerAccount: $customer->account,
            items: $resolvedItems,
            validUntil: $validated['valid_until'] ?? null,
            notes: $validated['notes'] ?? null,
        );

        return Response::structured($this->presenter->present($quote));
    }

    /**
     * @param  list<array{product: string, quantity: int|float|string}>  $items
     * @return list<array{product_id: int, quantity: int|float|string}>
     */
    private function resolveItems(array $items): array
    {
        $resolved = [];
        $productIds = [];
        $currencies = [];

        foreach ($items as $index => $item) {
            $product = $this->products->resolve($item['product']);

            if (in_array($product->id, $productIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.product" => '[repeated_product] A product cannot be repeated in the same quote.',
                ]);
            }

            $productIds[] = $product->id;
            $currencies[$product->currency] = true;
            $resolved[] = [
                'product_id' => $product->id,
                'quantity' => $item['quantity'],
            ];
        }

        if (count($currencies) > 1) {
            throw ValidationException::withMessages([
                'items' => '[mixed_currency] All products in a quote must use the same currency.',
            ]);
        }

        return $resolved;
    }
}
