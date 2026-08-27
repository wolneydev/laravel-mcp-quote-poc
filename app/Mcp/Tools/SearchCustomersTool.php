<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSellerAccount;
use App\Mcp\Lookups\CustomerLookup;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_customers')]
#[Description('Search customers by name, customer code (CUST-*), document, or customer account code (CLI-*). Returns at most 10 results. Document is a search input only and is not returned. Email, phone, contact name, and passwords are never returned.')]
#[IsReadOnly]
class SearchCustomersTool extends Tool
{
    use RequiresSellerAccount;

    public function __construct(private CustomerLookup $customers) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Customer name, customer code (CUST-*), document, or customer account code (CLI-*).')
                ->min(1)
                ->max(255)
                ->required(),
            'active_only' => $schema->boolean()
                ->description('When true, inactive customers are excluded.')
                ->default(true),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return.')
                ->min(1)
                ->max(CustomerLookup::MAX_RESULTS)
                ->default(CustomerLookup::MAX_RESULTS),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $this->sellerAccount($request);

        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:255'],
            'active_only' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.CustomerLookup::MAX_RESULTS],
        ]);

        $customers = $this->customers->search(
            $validated['query'],
            array_key_exists('active_only', $validated) ? (bool) $validated['active_only'] : true,
            (int) ($validated['limit'] ?? CustomerLookup::MAX_RESULTS),
        );

        return Response::structured([
            'customers' => $customers->map(fn ($customer): array => $this->customers->summary($customer))->values()->all(),
        ]);
    }
}
